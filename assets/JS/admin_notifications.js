(() => {
  const menu = document.getElementById('notification-menu');
  const summary = document.getElementById('notification-summary');
  const itemsContainer = document.getElementById('notification-items');
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

  if (!menu || !summary || !itemsContainer) return;

  function updateBadge(count) {
    const unreadCount = Math.max(0, Number(count) || 0);
    let badge = summary.querySelector('.notification-count');

    if (unreadCount === 0) {
      if (badge) badge.remove();
      summary.setAttribute('aria-label', 'Open clinic notifications; no unread notifications');
      return;
    }

    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'notification-count';
      summary.appendChild(badge);
    }
    badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
    summary.setAttribute('aria-label', `${unreadCount} unread clinic notifications`);
  }

  function renderNotifications(items, errorMessage = '') {
    itemsContainer.replaceChildren();
    if (errorMessage) {
      const error = document.createElement('p');
      error.className = 'notification-empty error';
      error.setAttribute('role', 'alert');
      error.textContent = errorMessage;
      itemsContainer.appendChild(error);
      return;
    }

    if (!items.length) {
      const empty = document.createElement('p');
      empty.className = 'notification-empty';
      empty.textContent = 'No notifications right now.';
      itemsContainer.appendChild(empty);
      return;
    }

    for (const item of items) {
      const link = document.createElement('a');
      link.className = 'notification-item';
      link.href = item.href;

      const dot = document.createElement('span');
      dot.className = `notification-dot ${item.kind}`;
      dot.setAttribute('aria-hidden', 'true');
      if (!item.unread) dot.style.backgroundColor = '#bdcbd6';
      const copy = document.createElement('span');
      copy.className = 'notification-copy';
      const label = document.createElement('strong');
      label.textContent = item.label;
      const detail = document.createElement('small');
      detail.textContent = item.detail;
      copy.append(label, detail);
      link.append(dot, copy);
      link.setAttribute('aria-label', `${item.label}, ${item.unread ? 'unread' : 'read'}`);

      if (item.count > 0) {
        const count = document.createElement('span');
        count.className = 'notification-item-count';
        count.textContent = new Intl.NumberFormat().format(item.count);
        link.appendChild(count);
      }

      itemsContainer.appendChild(link);
    }
  }

  let requestQueue = Promise.resolve();
  let requestPending = false;
  let markReadPending = false;

  function syncNotifications(markRead) {
    if (markRead) markReadPending = true;
    if (requestPending) return requestQueue;

    requestPending = true;
    const shouldMarkRead = markReadPending;
    markReadPending = false;
    requestQueue = requestQueue.then(async () => {
      const options = {
        method: shouldMarkRead ? 'POST' : 'GET',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
      };

      if (shouldMarkRead) {
        options.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
        options.body = new URLSearchParams({ csrf: csrfToken || '', action: 'read_all' });
      }

      const response = await fetch('notifications.php', options);
      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.error || `Notification request failed with status ${response.status}.`);
      }
      if (!Number.isInteger(data.unread_count) || !Array.isArray(data.items)) {
        throw new Error('The notification response was invalid.');
      }

      updateBadge(data.unread_count);
      if (!shouldMarkRead || menu.open) renderNotifications(data.items);
    }).catch((error) => {
      console.error('Unable to update clinic notifications:', error);
      if (menu.open) renderNotifications([], 'Could not update notifications. Please try again.');
    }).finally(() => {
      requestPending = false;
      if (markReadPending) syncNotifications(true);
    });

    return requestQueue;
  }

  menu.addEventListener('toggle', () => {
    if (menu.open) syncNotifications(true);
  });

  syncNotifications(false);
  window.setInterval(() => {
    if (!document.hidden) syncNotifications(false);
  }, 15000);
})();
