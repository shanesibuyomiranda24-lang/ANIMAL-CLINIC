(() => {
  const menu = document.getElementById('notification-menu');
  const summary = document.getElementById('notification-summary');
  const items = document.getElementById('notification-items');
  const markAll = document.getElementById('notification-mark-all');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const endpoint = '../api/notifications.php?session_context=client';

  if (!menu || !summary || !items) return;

  function updateBadge(count) {
    const unread = Math.max(0, Number(count) || 0);
    let badge = summary.querySelector('.notification-count');
    if (unread === 0) {
      if (badge) badge.remove();
      summary.setAttribute('aria-label', 'Open clinic notifications; no unread notifications');
      return;
    }
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'notification-count';
      summary.appendChild(badge);
    }
    badge.textContent = unread > 99 ? '99+' : String(unread);
    summary.setAttribute('aria-label', `${unread} unread clinic notifications`);
  }

  function render(rows, errorMessage = '') {
    items.replaceChildren();
    if (errorMessage) {
      const error = document.createElement('p');
      error.className = 'notification-empty error';
      error.setAttribute('role', 'alert');
      error.textContent = errorMessage;
      items.appendChild(error);
      return;
    }
    if (!rows.length) {
      const empty = document.createElement('p');
      empty.className = 'notification-empty';
      empty.textContent = 'No notifications right now.';
      items.appendChild(empty);
      return;
    }
    rows.forEach((row) => {
      const link = document.createElement('a');
      link.className = 'notification-item';
      link.href = row.href;
      link.dataset.notificationId = String(row.id);

      const dot = document.createElement('span');
      dot.className = `notification-dot ${row.kind}`;
      dot.setAttribute('aria-hidden', 'true');
      const copy = document.createElement('span');
      copy.className = 'notification-copy';
      const title = document.createElement('strong');
      title.textContent = row.label;
      const detail = document.createElement('small');
      detail.textContent = row.detail;
      copy.append(title, detail);
      link.append(dot, copy);
      if (row.unread) link.setAttribute('aria-label', `${row.label}, unread`);
      items.appendChild(link);
    });
  }

  async function load() {
    try {
      const response = await fetch(endpoint, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || `Request failed (${response.status}).`);
      if (!Number.isInteger(data.unread_count) || !Array.isArray(data.items)) {
        throw new Error('The notification response was invalid.');
      }
      updateBadge(data.unread_count);
      render(data.items);
    } catch (error) {
      console.error('Unable to load clinic notifications:', error);
      if (menu.open) render([], 'Could not load notifications. Please try again.');
    }
  }

  async function markRead(action, id = '') {
    const body = new URLSearchParams({ csrf, action });
    if (id) body.set('id', id);
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body,
      credentials: 'same-origin',
      cache: 'no-store',
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || `Request failed (${response.status}).`);
    return data;
  }

  menu.addEventListener('toggle', () => {
    if (menu.open) load();
  });

  items.addEventListener('click', async (event) => {
    const link = event.target.closest('a[data-notification-id]');
    if (!link) return;
    event.preventDefault();
    try {
      await markRead('read', link.dataset.notificationId);
    } catch (error) {
      console.error('Unable to mark notification as read:', error);
    }
    window.location.assign(link.href);
  });

  if (markAll) {
    markAll.addEventListener('click', async () => {
      markAll.disabled = true;
      try {
        await markRead('read_all');
        await load();
      } catch (error) {
        console.error('Unable to mark notifications as read:', error);
        render([], 'Could not update notifications. Please try again.');
      } finally {
        markAll.disabled = false;
      }
    });
  }

  load();
  window.setInterval(() => {
    if (!document.hidden) load();
  }, 30000);
})();
