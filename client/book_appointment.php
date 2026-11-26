<?php
// client/book_appointment.php - database-backed pet, service and schedule selection.
require_once __DIR__ . '/client_layout.php';

const BOOKING_PAYMENT_TIMINGS = ['At clinic', 'Advance request'];
const BOOKING_PAYMENT_METHODS = ['Cash', 'Card', 'GCash', 'Maya', 'MariBank', 'Bank Transfer', 'Other / ask clinic'];
const BOOKING_CAPACITY_STATUSES = ['Scheduled', 'Waiting', 'Check-in', 'In Consultation', 'Complete'];
const BOOKING_AFTERNOON_START_HOUR = 12;
const BOOKING_GROOMING_SERVICES = [
  'Ear Cleaning & Flea Check',
  'Full Grooming (Bath, Haircut, Nail Trim, Ear Cleaning)',
  'Nail Trimming',
];

function booking_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function booking_valid_date(string $value, string $format): bool
{
    $date = DateTime::createFromFormat('!' . $format, $value);
    return $date instanceof DateTime && $date->format($format) === $value;
}

function booking_last_bookable_date(): string
{
    return ((int) date('Y') + 1) . '-12-31';
}

function booking_half_day(int $timestamp): string
{
    return (int) date('G', $timestamp) < BOOKING_AFTERNOON_START_HOUR ? 'morning' : 'afternoon';
}

function booking_service_ids($values): array
{
    if (!is_array($values)) {
        return [];
    }

    $serviceIds = [];
    foreach ($values as $value) {
        $serviceId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($serviceId === false) {
            return [];
        }
        $serviceIds[(int) $serviceId] = (int) $serviceId;
    }

    return array_values($serviceIds);
}

function booking_services(PDO $pdo, array $serviceIds): array
{
    if (!$serviceIds) {
        throw new DomainException('Choose at least one service.');
    }

    $in = implode(',', array_fill(0, count($serviceIds), '?'));
    $statement = $pdo->prepare("SELECT service_id, service_name, base_price, duration_minutes, description FROM Services WHERE service_id IN ($in) AND is_active = 1");
    $statement->execute($serviceIds);
    $found = [];
    foreach ($statement->fetchAll() as $service) {
        $found[(int) $service['service_id']] = $service;
    }
    if (count($found) !== count($serviceIds)) {
        throw new DomainException('One or more selected services are no longer available. Choose again.');
    }

    $services = [];
    foreach ($serviceIds as $serviceId) {
        $services[] = $found[$serviceId];
    }
    booking_service_type($services);
    return $services;
}

function booking_owner_appointments(PDO $pdo, ?int $ownerId, string $from, string $to): array
{
    if ($ownerId === null) {
        return [];
    }

    $statuses = ACTIVE_STATUSES;
    $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
    $statement = $pdo->prepare(
        "SELECT a.appointment_date, a.duration_minutes
         FROM Appointments a
         JOIN Pets p ON p.pet_id = a.pet_id
         WHERE p.owner_id = ?
           AND a.appointment_date < DATE_ADD(?, INTERVAL 1 DAY)
           AND DATE_ADD(a.appointment_date, INTERVAL a.duration_minutes MINUTE) > ?
           AND a.status IN ($statusPlaceholders)
         ORDER BY a.appointment_date"
    );
    $statement->execute(array_merge([$ownerId, $to, $from . ' 00:00:00'], $statuses));

    $appointmentsByDate = [];
    foreach ($statement->fetchAll() as $row) {
        $start = strtotime((string) $row['appointment_date']);
        if ($start === false) {
            continue;
        }
        $appointmentsByDate[date('Y-m-d', $start)][] = [
            'start' => $start,
            'end' => $start + (int) $row['duration_minutes'] * 60,
        ];
    }

    return $appointmentsByDate;
}

function booking_cross_category_pet_appointments(PDO $pdo, ?int $petId, string $from, string $to, string $serviceType): array
{
    if ($petId === null || $petId < 1) {
        return [];
    }
    if (!in_array($serviceType, ['grooming', 'veterinary'], true)) {
        throw new InvalidArgumentException('Choose a valid appointment service type.');
    }

    $otherCategoryCondition = $serviceType === 'grooming'
        ? "s.service_category <> 'Grooming & Hygiene'"
        : "s.service_category = 'Grooming & Hygiene'";
    $statuses = ACTIVE_STATUSES;
    $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
    $statement = $pdo->prepare(
        "SELECT a.appointment_date
         FROM Appointments a
         WHERE a.pet_id = ?
           AND a.appointment_date < DATE_ADD(?, INTERVAL 1 DAY)
           AND DATE_ADD(a.appointment_date, INTERVAL a.duration_minutes MINUTE) > ?
           AND a.status IN ($statusPlaceholders)
           AND EXISTS (
               SELECT 1
               FROM AppointmentServices aps
               JOIN Services s ON s.service_id = aps.service_id
               WHERE aps.appointment_id = a.appointment_id
                 AND $otherCategoryCondition
           )
         ORDER BY a.appointment_date"
    );
    $statement->execute(array_merge([$petId, $to, $from . ' 00:00:00'], $statuses));

    $appointmentsByDate = [];
    foreach ($statement->fetchAll() as $row) {
        $start = strtotime((string) $row['appointment_date']);
        if ($start === false) {
            continue;
        }
        $appointmentsByDate[date('Y-m-d', $start)][] = $start;
    }

    return $appointmentsByDate;
}

  function booking_service_type(array $services): string
  {
    $hasGrooming = false;
    $hasVeterinary = false;
    foreach ($services as $service) {
      if (in_array((string) $service['service_name'], BOOKING_GROOMING_SERVICES, true)) {
        $hasGrooming = true;
      } else {
        $hasVeterinary = true;
      }
    }
    if ($hasGrooming && $hasVeterinary) {
      throw new DomainException('Book veterinary and grooming services separately. Choose services from one group at a time.');
    }
    return $hasGrooming ? 'grooming' : 'veterinary';
  }

  function booking_grooming_availability(PDO $pdo, array $services, int $duration, string $from, string $to, ?int $ownerId, ?int $petId): array
  {
    $scheduleStatement = $pdo->query(
      "SELECT g.groomer_id, g.full_name, g.contact_number, s.day_of_week, s.start_time, s.end_time
       FROM Groomers g
       JOIN Users u ON u.user_id = g.user_id AND u.status = 'active'
       JOIN Staff st ON st.staff_id = u.staff_id
          AND (st.can_manage_groomer_schedules = 1 OR st.`role` = 'Groomer')
       JOIN GroomerSchedules s ON s.groomer_id = g.groomer_id
       WHERE g.is_available = 1
       ORDER BY s.day_of_week, g.full_name, s.start_time"
    );
    $scheduleByDay = [];
    $groomerNames = [];
    $groomerContacts = [];
    foreach ($scheduleStatement->fetchAll() as $row) {
      $groomerId = (int) $row['groomer_id'];
      $scheduleByDay[(int) $row['day_of_week']][] = $row;
      $groomerNames[$groomerId] = (string) $row['full_name'];
      $groomerContacts[$groomerId] = (string) ($row['contact_number'] ?? '');
    }

    $groomerIds = array_keys($groomerNames);
    $breaks = [];
    $leaves = [];
    $booked = [];
    $ownerBooked = booking_owner_appointments($pdo, $ownerId, $from, $to);
    $petOtherCategoryBooked = booking_cross_category_pet_appointments($pdo, $petId, $from, $to, 'grooming');
    if ($groomerIds) {
      $in = implode(',', array_fill(0, count($groomerIds), '?'));
      $breakStatement = $pdo->prepare("SELECT groomer_id, day_of_week, start_time, end_time FROM GroomerScheduleBreaks WHERE groomer_id IN ($in)");
      $breakStatement->execute($groomerIds);
      foreach ($breakStatement->fetchAll() as $break) {
        $breaks[(int) $break['groomer_id']][(int) $break['day_of_week']][] = $break;
      }
      $leaveStatement = $pdo->prepare(
        "SELECT groomer_id, start_date, end_date
         FROM GroomerLeaves
         WHERE status = 'Approved' AND start_date <= ? AND end_date >= ? AND groomer_id IN ($in)"
      );
      $leaveStatement->execute(array_merge([$to, $from], $groomerIds));
      foreach ($leaveStatement->fetchAll() as $leave) {
        $leaves[(int) $leave['groomer_id']][] = $leave;
      }
      $activeStatuses = ACTIVE_STATUSES;
      $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
      $bookingStatement = $pdo->prepare(
        "SELECT aps.groomer_id, a.appointment_date, a.duration_minutes, a.status
         FROM AppointmentServices aps
         JOIN Appointments a ON a.appointment_id = aps.appointment_id
         WHERE aps.groomer_id IN ($in)
           AND a.appointment_date < DATE_ADD(?, INTERVAL 1 DAY)
           AND DATE_ADD(a.appointment_date, INTERVAL a.duration_minutes MINUTE) > ?
           AND a.status IN ($statusPlaceholders)
         ORDER BY a.appointment_date"
      );
      $bookingStatement->execute(array_merge($groomerIds, [$to, $from . ' 00:00:00'], $activeStatuses));
      foreach ($bookingStatement->fetchAll() as $row) {
        $booked[(int) $row['groomer_id']][] = [
          'start' => strtotime((string) $row['appointment_date']),
          'end' => strtotime((string) $row['appointment_date']) + (int) $row['duration_minutes'] * 60,
        ];
      }
    }

    $holidays = [];
    $holidayStatement = $pdo->prepare('SELECT holiday_date FROM ClinicHolidays WHERE holiday_date BETWEEN ? AND ?');
    $holidayStatement->execute([$from, $to]);
    foreach ($holidayStatement->fetchAll(PDO::FETCH_COLUMN) as $holiday) {
      $holidays[(string) $holiday] = true;
    }

    $today = date('Y-m-d');
    $lastBookableDate = booking_last_bookable_date();
    $earliest = time() + LEAD_MINUTES * 60;
    $days = [];
    $periodEnd = (new DateTimeImmutable($to))->modify('+1 day');
    foreach (new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), $periodEnd) as $dateObject) {
      $date = $dateObject->format('Y-m-d');
      $dayOfWeek = (int) $dateObject->format('N');
      $day = [
        'selectable' => $date >= $today && $date <= $lastBookableDate,
        'available' => false,
        'holiday' => isset($holidays[$date]),
        'reason' => '',
        'slots' => [],
      ];
      if ($date < $today) {
        $day['selectable'] = false;
        $day['reason'] = 'Past dates cannot be booked.';
      } elseif ($date > $lastBookableDate) {
        $day['selectable'] = false;
        $day['reason'] = 'Choose a date within the booking window.';
      } elseif ($day['holiday']) {
        $day['selectable'] = false;
        $day['reason'] = 'The clinic is closed for a holiday.';
      } elseif (empty($scheduleByDay[$dayOfWeek])) {
        $day['reason'] = 'No groomer has Admin-approved working hours on this day.';
      } else {
        $ownerConflict = false;
        $petHalfDayConflict = false;
        foreach ($scheduleByDay[$dayOfWeek] as $schedule) {
          $groomerId = (int) $schedule['groomer_id'];
          $onLeave = false;
          foreach ($leaves[$groomerId] ?? [] as $leave) {
            if ($date >= $leave['start_date'] && $date <= $leave['end_date']) {
              $onLeave = true;
              break;
            }
          }
          if ($onLeave) {
            continue;
          }
          $open = strtotime($date . ' ' . $schedule['start_time']);
          $close = strtotime($date . ' ' . $schedule['end_time']);
          for ($slotStart = $open; $slotStart + $duration * 60 <= $close; $slotStart += SLOT_INTERVAL_MINUTES * 60) {
            if ($slotStart < $earliest) {
              continue;
            }
            $slotEnd = $slotStart + $duration * 60;
            $blocked = false;
            foreach ($breaks[$groomerId][$dayOfWeek] ?? [] as $break) {
              $breakStart = strtotime($date . ' ' . $break['start_time']);
              $breakEnd = strtotime($date . ' ' . $break['end_time']);
              if ($slotStart < $breakEnd && $slotEnd > $breakStart) {
                $blocked = true;
                break;
              }
            }
            if ($blocked) {
              continue;
            }
            $slotHalfDay = booking_half_day($slotStart);
            foreach ($petOtherCategoryBooked[$date] ?? [] as $appointmentStart) {
              $appointmentHalfDay = booking_half_day($appointmentStart);
              if ($slotHalfDay === $appointmentHalfDay) {
                $blocked = true;
                $petHalfDayConflict = true;
                break;
              }
            }
            if ($blocked) {
              continue;
            }
            foreach ($ownerBooked[$date] ?? [] as $appointment) {
              if ($slotStart < $appointment['end'] && $slotEnd > $appointment['start']) {
                $blocked = true;
                $ownerConflict = true;
                break;
              }
            }
            if ($blocked) {
              continue;
            }
            foreach ($booked[$groomerId] ?? [] as $appointment) {
              if ($slotStart < $appointment['end'] && $slotEnd > $appointment['start']) {
                $blocked = true;
                break;
              }
            }
            if ($blocked) {
              continue;
            }
            $time = date('H:i', $slotStart);
            $day['slots'][$time] ??= ['time' => $time, 'label' => date('g:i A', $slotStart), 'groomers' => []];
            $day['slots'][$time]['groomers'][$groomerId] = [
              'groomer_id' => $groomerId,
              'full_name' => $groomerNames[$groomerId],
              'contact_number' => $groomerContacts[$groomerId],
            ];
          }
        }
        foreach ($day['slots'] as $time => $slot) {
          $availableGroomers = array_values($slot['groomers']);
          usort($availableGroomers, static function (array $left, array $right): int {
            return strcmp($left['full_name'], $right['full_name']);
          });
          $day['slots'][$time]['groomers'] = $availableGroomers;
          $day['slots'][$time]['assigned_groomer'] = $availableGroomers[0];
        }
        ksort($day['slots']);
        $day['available'] = count($day['slots']) > 0;
        if (!$day['available']) {
          $day['reason'] = $petHalfDayConflict
            ? 'This pet already has an appointment in this half of the day. Choose a time in the opposite half.'
            : ($ownerConflict
            ? 'You already have an appointment during the available times. Choose another time.'
            : 'No Admin-approved groomer time remains for this date.');
        }
      }
      $days[$date] = $day;
    }

    return [
      'services' => $services,
      'service_type' => 'grooming',
      'duration' => $duration,
      'fee' => array_sum(array_map(static function (array $service): float { return (float) $service['base_price']; }, $services)),
      'days' => $days,
    ];
  }

  function booking_availability(PDO $pdo, array $serviceIds, string $from, string $to, ?int $ownerId = null, ?int $petId = null): array
{
    $services = booking_services($pdo, $serviceIds);
    $serviceType = booking_service_type($services);
    $duration = array_sum(array_map(static function (array $service): int {
        return (int) $service['duration_minutes'];
    }, $services));
    if ($serviceType === 'grooming') {
      return booking_grooming_availability($pdo, $services, $duration, $from, $to, $ownerId, $petId);
    }
    $activeStatuses = ACTIVE_STATUSES;
    $availabilityStatuses = array_values(array_unique(array_merge($activeStatuses, BOOKING_CAPACITY_STATUSES)));
    $in = implode(',', array_fill(0, count($serviceIds), '?'));

    $statement = $pdo->prepare(
        'SELECT v.vet_id, v.full_name, v.max_appointments_per_day, s.day_of_week, s.start_time, s.end_time
         FROM Veterinarians v
         JOIN VetServices vs ON vs.vet_id = v.vet_id
         JOIN VetSchedules s ON s.vet_id = v.vet_id
         WHERE vs.service_id IN (' . $in . ') AND v.is_available = 1
         GROUP BY v.vet_id, v.full_name, v.max_appointments_per_day, s.day_of_week, s.start_time, s.end_time
         HAVING COUNT(DISTINCT vs.service_id) = ?
         ORDER BY s.day_of_week, v.vet_id, s.start_time'
    );
    $statement->execute(array_merge($serviceIds, [count($serviceIds)]));
    $scheduleRows = $statement->fetchAll();

    $scheduleByDay = [];
    $vetNames = [];
    foreach ($scheduleRows as $row) {
        $vetId = (int) $row['vet_id'];
        $day = (int) $row['day_of_week'];
        $scheduleByDay[$day][] = $row;
        $vetNames[$vetId] = (string) $row['full_name'];
    }

    $vetIds = array_keys($vetNames);
    $breaks = [];
    $leaves = [];
    $booked = [];
    $ownerBooked = booking_owner_appointments($pdo, $ownerId, $from, $to);
    $petOtherCategoryBooked = booking_cross_category_pet_appointments($pdo, $petId, $from, $to, 'veterinary');
    if ($vetIds) {
        $in = implode(',', array_fill(0, count($vetIds), '?'));

        $statement = $pdo->prepare("SELECT vet_id, day_of_week, start_time, end_time FROM VetScheduleBreaks WHERE vet_id IN ($in)");
        $statement->execute($vetIds);
        foreach ($statement->fetchAll() as $row) {
            $breaks[(int) $row['vet_id']][(int) $row['day_of_week']][] = $row;
        }

        $statement = $pdo->prepare(
            "SELECT vet_id, start_date, end_date
             FROM VetLeaves
             WHERE status = 'Approved' AND start_date <= ? AND end_date >= ? AND vet_id IN ($in)"
        );
        $statement->execute(array_merge([$to, $from], $vetIds));
        foreach ($statement->fetchAll() as $row) {
            $leaves[(int) $row['vet_id']][] = $row;
        }

        $statement = $pdo->prepare(
            "SELECT vet_id, appointment_date, duration_minutes, status
             FROM Appointments
             WHERE vet_id IN ($in)
               AND appointment_date < DATE_ADD(?, INTERVAL 1 DAY)
               AND DATE_ADD(appointment_date, INTERVAL duration_minutes MINUTE) > ?
               AND status IN (" . implode(',', array_fill(0, count($availabilityStatuses), '?')) . ")
             ORDER BY appointment_date"
        );
        $statement->execute(array_merge($vetIds, [$to, $from . ' 00:00:00'], $availabilityStatuses));
        foreach ($statement->fetchAll() as $row) {
            $booked[(int) $row['vet_id']][] = [
                'start' => strtotime((string) $row['appointment_date']),
                'end' => strtotime((string) $row['appointment_date']) + (int) $row['duration_minutes'] * 60,
                'date' => substr((string) $row['appointment_date'], 0, 10),
                'status' => (string) $row['status'],
            ];
        }
    }

    $holidays = [];
    $statement = $pdo->prepare('SELECT holiday_date FROM ClinicHolidays WHERE holiday_date BETWEEN ? AND ?');
    $statement->execute([$from, $to]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $holiday) {
        $holidays[(string) $holiday] = true;
    }

    $today = date('Y-m-d');
    $lastBookableDate = booking_last_bookable_date();
    $earliest = time() + LEAD_MINUTES * 60;
    $days = [];
    $periodEnd = (new DateTimeImmutable($to))->modify('+1 day');
    $period = new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), $periodEnd);

    foreach ($period as $dateObject) {
        $date = $dateObject->format('Y-m-d');
        $dayOfWeek = (int) $dateObject->format('N');
        $day = [
            'selectable' => $date >= $today && $date <= $lastBookableDate,
            'available' => false,
            'holiday' => isset($holidays[$date]),
            'reason' => '',
            'slots' => [],
        ];

        if ($date < $today) {
            $day['selectable'] = false;
            $day['reason'] = 'Past dates cannot be booked.';
        } elseif ($date > $lastBookableDate) {
            $day['selectable'] = false;
            $day['reason'] = 'Choose a date within the booking window.';
        } elseif ($day['holiday']) {
            $day['selectable'] = false;
            $day['reason'] = 'The clinic is closed for a holiday.';
        } elseif (empty($scheduleByDay[$dayOfWeek])) {
          $day['reason'] = $serviceType === 'grooming'
            ? 'No appointment time is available for grooming services on this day.'
            : 'No qualified veterinarian is scheduled for this service on this day.';
        } else {
            $capacityReached = false;
            $ownerConflict = false;
            $petHalfDayConflict = false;
            foreach ($scheduleByDay[$dayOfWeek] as $schedule) {
                $vetId = (int) $schedule['vet_id'];
                $onLeave = false;
                foreach ($leaves[$vetId] ?? [] as $leave) {
                    if ($date >= $leave['start_date'] && $date <= $leave['end_date']) {
                        $onLeave = true;
                        break;
                    }
                }
                if ($onLeave) {
                    continue;
                }

                $dailyLoad = 0;
                foreach ($booked[$vetId] ?? [] as $appointment) {
                    if ($appointment['date'] === $date && in_array($appointment['status'], BOOKING_CAPACITY_STATUSES, true)) {
                        $dailyLoad++;
                    }
                }
                if ($dailyLoad >= (int) $schedule['max_appointments_per_day']) {
                    $capacityReached = true;
                    continue;
                }

                $open = strtotime($date . ' ' . $schedule['start_time']);
                $close = strtotime($date . ' ' . $schedule['end_time']);
                for ($slotStart = $open; $slotStart + $duration * 60 <= $close; $slotStart += SLOT_INTERVAL_MINUTES * 60) {
                    if ($slotStart < $earliest) {
                        continue;
                    }

                    $slotEnd = $slotStart + $duration * 60;
                    $hasBreak = false;
                    foreach ($breaks[$vetId][$dayOfWeek] ?? [] as $break) {
                        $breakStart = strtotime($date . ' ' . $break['start_time']);
                        $breakEnd = strtotime($date . ' ' . $break['end_time']);
                        if ($slotStart < $breakEnd && $slotEnd > $breakStart) {
                            $hasBreak = true;
                            break;
                        }
                    }
                    if ($hasBreak) {
                        continue;
                    }

                    $slotHalfDay = booking_half_day($slotStart);
                    $sameHalfDayAppointment = false;
                    foreach ($petOtherCategoryBooked[$date] ?? [] as $appointmentStart) {
                        $appointmentHalfDay = booking_half_day($appointmentStart);
                        if ($slotHalfDay === $appointmentHalfDay) {
                            $sameHalfDayAppointment = true;
                            break;
                        }
                    }
                    if ($sameHalfDayAppointment) {
                        $petHalfDayConflict = true;
                        continue;
                    }

                    $overlaps = false;
                    foreach ($ownerBooked[$date] ?? [] as $appointment) {
                        if ($slotStart < $appointment['end'] && $slotEnd > $appointment['start']) {
                            $overlaps = true;
                            $ownerConflict = true;
                            break;
                        }
                    }
                    foreach ($booked[$vetId] ?? [] as $appointment) {
                        if (!$overlaps
                            && in_array($appointment['status'], $activeStatuses, true)
                            && $slotStart < $appointment['end'] && $slotEnd > $appointment['start']) {
                            $overlaps = true;
                            break;
                        }
                    }
                    if ($overlaps) {
                        continue;
                    }

                    $time = date('H:i', $slotStart);
                    if (!isset($day['slots'][$time])) {
                        $day['slots'][$time] = ['time' => $time, 'label' => date('g:i A', $slotStart), 'vets' => []];
                    }
                    $day['slots'][$time]['vets'][$vetId] = [
                        'vet_id' => $vetId,
                        'full_name' => $vetNames[$vetId],
                        'daily_load' => $dailyLoad,
                    ];
                }
            }

            foreach ($day['slots'] as $time => $slot) {
                $availableVets = array_values($slot['vets']);
                usort($availableVets, static function (array $left, array $right): int {
                    return ($left['daily_load'] <=> $right['daily_load'])
                        ?: ($left['vet_id'] <=> $right['vet_id']);
                });
                $day['slots'][$time]['vets'] = $availableVets;
                $day['slots'][$time]['assigned_vet'] = $availableVets[0];
            }
            ksort($day['slots']);
            $day['available'] = count($day['slots']) > 0;
            if (!$day['available']) {
                $day['reason'] = $petHalfDayConflict
                  ? 'This pet already has an appointment in this half of the day. Choose a time in the opposite half.'
                  : ($ownerConflict
                  ? 'You already have an appointment during the available times. Choose another time.'
                  : ($capacityReached
                  ? ($serviceType === 'grooming'
                    ? 'All available clinic appointment capacity is booked for this day.'
                    : 'All qualified veterinarians have reached their daily appointment capacity.')
                  : 'No available clinic appointment time remains for this date.'));
            }
        }

        $days[$date] = $day;
    }

    return [
        'services' => $services,
        'service_type' => $serviceType,
        'duration' => $duration,
        'fee' => array_sum(array_map(static function (array $service): float {
            return (float) $service['base_price'];
        }, $services)),
        'days' => $days,
    ];
}

function booking_availability_window(PDO $pdo, array $serviceIds, ?int $ownerId = null, ?int $petId = null): array
{
    $today = date('Y-m-d');
    $maximumDate = booking_last_bookable_date();
    $availability = booking_availability($pdo, $serviceIds, $today, $maximumDate, $ownerId, $petId);
    $currentMonth = new DateTimeImmutable(date('Y-m-01'));
    $initialEndMonth = $currentMonth->modify('+1 month');
    $firstAvailableMonth = null;

    foreach ($availability['days'] as $date => $day) {
        if ($day['available']) {
            $month = substr($date, 0, 7);
            if ($firstAvailableMonth === null || $month < $firstAvailableMonth) {
                $firstAvailableMonth = $month;
            }
        }
    }

    $initialEndDate = $initialEndMonth->modify('last day of this month')->format('Y-m-d');
    $lastBookableDate = $initialEndDate;
    if ($firstAvailableMonth !== null && $firstAvailableMonth > $initialEndMonth->format('Y-m')) {
        $lastBookableDate = (new DateTimeImmutable($firstAvailableMonth . '-01'))
            ->modify('last day of this month')
            ->format('Y-m-d');
    }
    if ($lastBookableDate > $maximumDate) {
        $lastBookableDate = $maximumDate;
    }

    foreach ($availability['days'] as $date => &$day) {
        if ($date > $lastBookableDate) {
            $day['selectable'] = false;
            $day['available'] = false;
            $day['reason'] = 'More dates open when no appointment times remain in the current booking window.';
            $day['slots'] = [];
        }
    }
    unset($day);

    return [
        'availability' => $availability,
        'last_bookable_date' => $lastBookableDate,
    ];
}

$c = client_boot();
$pdo = $c['pdo'];
$jsonRoute = isset($_GET['availability']) || isset($_GET['slots']) || isset($_GET['preview']);
if ($jsonRoute) {
    if ($c['dbError'] || !$pdo instanceof PDO) {
        booking_json(['error' => 'Booking availability is temporarily unavailable.'], 503);
    }

    try {
        $serviceIds = booking_service_ids($_GET['service_ids'] ?? []);
        if (!$serviceIds) {
            throw new DomainException('Choose one or more valid services.');
        }
        $petIdInput = filter_var($_GET['pet_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($petIdInput === false) {
          throw new DomainException('Choose a pet before checking appointment availability.');
        }
        $petStatement = $pdo->prepare('SELECT pet_id FROM Pets WHERE pet_id = ? AND owner_id = ?');
        $petStatement->execute([(int) $petIdInput, (int) $c['ownerId']]);
        $petId = (int) $petStatement->fetchColumn();
        if (!$petId) {
          throw new DomainException('That pet is not registered to your account.');
        }

        if (isset($_GET['availability'])) {
            $month = (string) ($_GET['month'] ?? '');
            if (!booking_valid_date($month, 'Y-m')) {
                throw new DomainException('Choose a valid calendar month.');
            }
            $window = booking_availability_window($pdo, $serviceIds, (int) $c['ownerId'], $petId);
            $availability = $window['availability'];
            $calendarDays = [];
            foreach ($availability['days'] as $date => $day) {
                if (substr($date, 0, 7) !== $month) {
                    continue;
                }
                $calendarDays[$date] = [
                    'selectable' => $day['selectable'],
                    'available' => $day['available'],
                    'holiday' => $day['holiday'],
                    'reason' => $day['reason'],
                ];
            }
            booking_json([
                'month' => $month,
                'services' => array_map(static function (array $service): string {
                    return (string) $service['service_name'];
                }, $availability['services']),
                'service_type' => $availability['service_type'],
                'duration' => $availability['duration'],
                'fee' => $availability['fee'],
                'days' => $calendarDays,
            ]);
        }

        $date = (string) ($_GET['date'] ?? '');
        if (!booking_valid_date($date, 'Y-m-d')) {
            throw new DomainException('Choose a valid appointment date.');
        }
        $window = booking_availability_window($pdo, $serviceIds, (int) $c['ownerId'], $petId);
        $availability = $window['availability'];
        $day = $availability['days'][$date] ?? null;
        if (isset($_GET['preview'])) {
            $time = (string) ($_GET['time'] ?? '');
            if (!$day || !preg_match('/\A\d{2}:\d{2}\z/', $time) || empty($day['slots'][$time])) {
                throw new DomainException('That appointment time is no longer available. Choose another time.');
            }
            $slot = $day['slots'][$time];
            if ($availability['service_type'] === 'grooming') {
              $assigned = $slot['assigned_groomer'];
              booking_json([
                'date' => $date,
                'date_label' => date('l, F j, Y', strtotime($date)),
                'time' => $time,
                'time_label' => $slot['label'],
                'service_type' => 'grooming',
                'groomer_id' => (int) $assigned['groomer_id'],
                'groomer_name' => $assigned['full_name'],
                'groomer_contact' => $assigned['contact_number'],
                'service_names' => array_map(static function (array $service): string {
                  return (string) $service['service_name'];
                }, $availability['services']),
                'duration' => $availability['duration'],
                'fee' => $availability['fee'],
              ]);
            }
            $assigned = $slot['assigned_vet'];
            booking_json([
                'date' => $date,
                'date_label' => date('l, F j, Y', strtotime($date)),
                'time' => $time,
                'time_label' => $slot['label'],
              'service_type' => 'veterinary',
                'vet_id' => (int) $assigned['vet_id'],
                'vet_name' => $assigned['full_name'],
                'service_names' => array_map(static function (array $service): string {
                    return (string) $service['service_name'];
                }, $availability['services']),
                'duration' => $availability['duration'],
                'fee' => $availability['fee'],
            ]);
        }

        $slots = [];
        foreach ($day['slots'] ?? [] as $slot) {
          $assigned = $availability['service_type'] === 'grooming' ? $slot['assigned_groomer'] : $slot['assigned_vet'];
          $slots[] = [
            'time' => $slot['time'],
            'label' => $slot['label'],
            'provider_name' => $assigned['full_name'],
            'provider_contact' => $availability['service_type'] === 'grooming' ? $assigned['contact_number'] : '',
            'provider_type' => $availability['service_type'] === 'grooming' ? 'groomer' : 'veterinarian',
          ];
        }
        booking_json([
            'date' => $date,
          'service_type' => $availability['service_type'],
            'slots' => $slots,
            'message' => $day['reason'] ?? 'Choose a date within the booking window.',
        ]);
    } catch (DomainException $ex) {
        booking_json(['error' => $ex->getMessage()], 400);
    } catch (PDOException $ex) {
        error_log('Booking availability query failed: ' . $ex->getMessage());
        booking_json(['error' => 'Availability could not be checked. Please try again.'], 503);
    }
}

$error = '';
$old = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : [];
if (!$c['dbError'] && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && posted_string('action') === 'book') {
    $petChoice = posted_string('pet_id');
    $serviceIds = booking_service_ids($_POST['service_ids'] ?? []);
    $date = posted_string('date');
    $time = posted_string('time');
    $paymentTiming = posted_string('payment_timing_preference');
    $paymentMethod = posted_string('preferred_payment_method');
    $assignedVetInput = filter_var(posted_string('assigned_vet_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $assignedGroomerInput = filter_var(posted_string('assigned_groomer_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $notes = mb_substr(trim(posted_string('notes')), 0, 255);
    $petIdInput = filter_var($petChoice, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $validDateAndTime = booking_valid_date($date, 'Y-m-d') && preg_match('/\A\d{2}:\d{2}\z/', $time);

    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($petIdInput === false) {
        $error = 'Choose one of your registered pets.';
    } elseif (!$serviceIds) {
        $error = 'Choose one or more services.';
    } elseif (!in_array($paymentTiming, BOOKING_PAYMENT_TIMINGS, true) || !in_array($paymentMethod, BOOKING_PAYMENT_METHODS, true)) {
        $error = 'Choose a payment preference and preferred method.';
    } elseif (!$validDateAndTime) {
        $error = 'Choose an available date and time.';
    } else {
        try {
            $pdo->beginTransaction();

            $lockOwner = $pdo->prepare('SELECT owner_id FROM PetOwners WHERE owner_id = ? FOR UPDATE');
            $lockOwner->execute([(int) $c['ownerId']]);

            $petCheck = $pdo->prepare('SELECT pet_id FROM Pets WHERE pet_id = ? AND owner_id = ? FOR UPDATE');
            $petCheck->execute([(int) $petIdInput, (int) $c['ownerId']]);
            $petId = (int) $petCheck->fetchColumn();
            if (!$petId) {
                throw new DomainException('That pet is not registered to your account.');
            }

            $selectedServices = booking_services($pdo, $serviceIds);
            $serviceType = booking_service_type($selectedServices);
            if ($serviceType === 'grooming') {
              if ($assignedGroomerInput === false) {
                throw new DomainException('Choose an available groomer time before submitting.');
              }
              $lockGroomer = $pdo->prepare(
                "SELECT g.groomer_id
                 FROM Groomers g
                 JOIN Users u ON u.user_id = g.user_id AND u.status = 'active'
                 JOIN Staff st ON st.staff_id = u.staff_id
                   AND (st.can_manage_groomer_schedules = 1 OR st.`role` = 'Groomer')
                 WHERE g.groomer_id = ? AND g.is_available = 1
                 FOR UPDATE"
              );
              $lockGroomer->execute([(int) $assignedGroomerInput]);
              if (!$lockGroomer->fetchColumn()) {
                throw new DomainException('That groomer is no longer available. Choose another appointment time.');
              }
            } else {
              if ($assignedVetInput === false) {
                throw new DomainException('Review the veterinarian assignment before submitting.');
              }
              $servicePlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
              $lockVets = $pdo->prepare(
                'SELECT v.vet_id
                 FROM Veterinarians v
                 JOIN VetServices vs ON vs.vet_id = v.vet_id
                 WHERE vs.service_id IN (' . $servicePlaceholders . ') AND v.is_available = 1
                 ORDER BY v.vet_id
                 FOR UPDATE'
              );
              $lockVets->execute($serviceIds);
              $lockVets->fetchAll(PDO::FETCH_COLUMN);
            }

            $window = booking_availability_window($pdo, $serviceIds, (int) $c['ownerId'], $petId);
            if ($date > $window['last_bookable_date']) {
                throw new DomainException('That date is outside the current booking window. Choose an available date.');
            }
            $availability = $window['availability'];
            $day = $availability['days'][$date] ?? null;
            $slot = $day['slots'][$time] ?? null;
            if (!$day || !$day['selectable'] || !$slot) {
                throw new DomainException('That time is no longer available. Choose another date and time.');
            }
            $assignedVet = null;
            $assignedGroomer = null;
            if ($serviceType === 'grooming') {
              $assignedGroomer = $slot['assigned_groomer'];
              if ((int) $assignedGroomer['groomer_id'] !== (int) $assignedGroomerInput) {
                throw new DomainException('Groomer availability changed while you were reviewing. Please choose another time.');
              }
            } else {
              $assignedVet = $slot['assigned_vet'];
              if ((int) $assignedVet['vet_id'] !== (int) $assignedVetInput) {
                throw new DomainException('Availability changed while you were reviewing. Please check the veterinarian and try again.');
              }
            }

            $appointmentDate = $date . ' ' . $time . ':00';
            $insertAppointment = $pdo->prepare(
                "INSERT INTO Appointments
                    (pet_id, vet_id, appointment_date, duration_minutes, status, client_notes,
                     payment_timing_preference, preferred_payment_method)
                 VALUES (?, ?, ?, ?, 'Pending', ?, ?, ?)"
            );
            $insertAppointment->execute([
                $petId,
                $assignedVet ? (int) $assignedVet['vet_id'] : null,
                $appointmentDate,
                $availability['duration'],
                $notes !== '' ? $notes : null,
                $paymentTiming,
                $paymentMethod,
            ]);
            $appointmentId = (int) $pdo->lastInsertId();
            $insertAppointmentService = $pdo->prepare('INSERT INTO AppointmentServices (appointment_id, service_id, groomer_id, charged_price) VALUES (?,?,?,?)');
            foreach ($availability['services'] as $service) {
              $insertAppointmentService->execute([
                $appointmentId,
                (int) $service['service_id'],
                $assignedGroomer ? (int) $assignedGroomer['groomer_id'] : null,
                (float) $service['base_price'],
              ]);
            }

            $petNameStatement = $pdo->prepare('SELECT pet_name FROM Pets WHERE pet_id = ?');
            $petNameStatement->execute([$petId]);
            $petNameForNotice = (string) $petNameStatement->fetchColumn();
            $serviceNames = array_map(static function (array $service): string {
                return (string) $service['service_name'];
            }, $availability['services']);
            $notice = 'Appointment request for ' . $petNameForNotice . ' (' . implode(', ', $serviceNames) . ') on ' . date('M j, Y g:i A', strtotime($appointmentDate))
                . '. Payment preference: ' . $paymentTiming . ' via ' . $paymentMethod . '.';
            $frontDeskUsers = array_merge(notification_role_users($pdo, 'staff'), notification_role_users($pdo, 'admin'));
            $staffNotified = notification_send_users(
                $pdo,
                $frontDeskUsers,
                'appointment',
                'New appointment request',
                $notice,
                'staff/appointments.php',
                'appointment_request_staff_' . $appointmentId
            );
            notification_send_users(
                $pdo,
                notification_owner_users($pdo, (int) $c['ownerId']),
                'appointment',
                'Appointment request received',
                'Your request for ' . $petNameForNotice . ' is pending clinic confirmation.',
                'client/appointments.php',
                'appointment_request_owner_' . $appointmentId
            );
            if ($staffNotified === 0) {
                error_log('Appointment request created without an active staff/admin notification recipient.');
            }

            $pdo->commit();
            header('Location: appointments.php?ok=booked');
            exit;
        } catch (DomainException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $ex->getMessage();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Client booking failed: ' . $ex->getMessage());
            $error = $ex->getCode() === '45000'
                ? 'That appointment time was just taken. Choose another available time.'
                : 'We could not submit the request. Please try again or call the clinic.';
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Client booking failed: ' . $ex->getMessage());
            $error = $ex instanceof InvalidArgumentException
                ? $ex->getMessage()
                : 'We could not submit the request. Please try again or call the clinic.';
        }
    }
}

$pets = $c['dbError'] ? [] : qall(
    $pdo,
    'SELECT pet_id, owner_id, pet_name, species, breed, birth_date, gender, photo_updated_at FROM Pets WHERE owner_id = ? ORDER BY pet_name',
    [$c['ownerId']]
);
$services = $c['dbError'] ? [] : qall(
    $pdo,
    "SELECT service_id, service_name, base_price, duration_minutes, description
     FROM Services WHERE is_active = 1
     ORDER BY CASE WHEN service_name = 'Other / Request a Quote' THEN 1 ELSE 0 END, service_name"
);
$serviceGroups = ['veterinary' => [], 'grooming' => []];
foreach ($services as $service) {
  $group = in_array((string) $service['service_name'], BOOKING_GROOMING_SERVICES, true) ? 'grooming' : 'veterinary';
  $serviceGroups[$group][] = $service;
}
$preselect = (string) ($old['pet_id'] ?? ($_GET['pet'] ?? ''));
$petIds = array_map(static function (array $pet): string {
    return (string) $pet['pet_id'];
}, $pets);
if (!in_array($preselect, $petIds, true)) {
    $preselect = $petIds ? $petIds[0] : '';
}
$oldServiceIds = booking_service_ids($old['service_ids'] ?? []);
$selectedMonth = date('Y-m');

client_header($c, 'Book a Visit', 'Choose your pet, service and an available time', 'book');
?>
        <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <section class="panel booking-panel">
          <div class="panel-header"><h2>Book an appointment</h2><span class="data-count">Availability updates from clinic schedules</span></div>
          <div class="panel-body">
            <?php if (!$services): ?>
              <div class="empty"><strong>No services are available to book</strong>Please contact the clinic.</div>
            <?php else: ?>
              <div class="booking-progress-shell">
                <ol class="booking-progress" id="booking-progress" aria-label="Appointment booking progress">
                  <li class="on" aria-current="step"><span class="booking-progress-number">01</span><span class="booking-progress-label">Pet &amp; service</span></li>
                  <li><span class="booking-progress-number">02</span><span class="booking-progress-label">Date &amp; time</span></li>
                  <li><span class="booking-progress-number">03</span><span class="booking-progress-label">Payment method</span></li>
                  <li><span class="booking-progress-number">04</span><span class="booking-progress-label">Review</span></li>
                </ol>
                <div class="booking-progress-track" id="booking-progress-track" role="progressbar" aria-label="Booking progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                  <span id="booking-progress-fill"></span>
                </div>
              </div>
              <form method="post" action="book_appointment.php" enctype="multipart/form-data" id="book-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="book">
                <input type="hidden" name="date" id="selected-date" value="<?= e($old['date'] ?? '') ?>">
                <input type="hidden" name="time" id="selected-time" value="<?= e($old['time'] ?? '') ?>">
                <input type="hidden" name="assigned_vet_id" id="assigned-vet-id" value="<?= e($old['assigned_vet_id'] ?? '') ?>">
                <input type="hidden" name="assigned_groomer_id" id="assigned-groomer-id" value="<?= e($old['assigned_groomer_id'] ?? '') ?>">

                <section class="booking-section" id="pet-service-section" aria-labelledby="pet-service-heading">
                  <div class="booking-section-heading"><span class="booking-section-number">01</span><div><h3 id="pet-service-heading">Who is the visit for?</h3><p>Select one pet, then choose the care they need.</p></div></div>
                  <fieldset class="pet-picker">
                    <legend>Your pets</legend>
                    <div class="pet-options">
                      <?php foreach ($pets as $pet): $photoUrl = pet_photo_url($pet['photo_updated_at'] ?? null, (int) $pet['pet_id']); ?>
                        <label class="pet-choice">
                          <input type="radio" name="pet_id" value="<?= (int) $pet['pet_id'] ?>" data-pet-name="<?= e($pet['pet_name']) ?>" data-photo="<?= e($photoUrl) ?>" <?= $preselect === (string) $pet['pet_id'] ? 'checked' : '' ?> required>
                          <span class="pet-choice-card">
                            <img src="<?= e($photoUrl ?: '../assets/images/pet.png') ?>" alt="" loading="lazy" decoding="async">
                            <span class="pet-choice-copy"><strong><?= e($pet['pet_name']) ?></strong><small><?= e($pet['species']) ?><?= !empty($pet['breed']) ? ' · ' . e($pet['breed']) : '' ?></small></span>
                            <span class="pet-choice-check" aria-hidden="true">✓</span>
                          </span>
                        </label>
                      <?php endforeach; ?>
                      <a class="pet-choice add-pet-choice" href="my_pets.php?return_to=booking">
                        <span class="pet-choice-card">
                          <span class="pet-add-icon" aria-hidden="true">+</span>
                          <span class="pet-choice-copy"><strong>Add a pet</strong><small>Save a profile to My Pets</small></span>
                        </span>
                      </a>
                    </div>
                    <?php if (!$pets): ?><p class="pet-picker-empty">Add a pet profile to continue. You’ll return here with the new pet selected.</p><?php endif; ?>
                  </fieldset>
                  <fieldset class="service-picker">
                    <legend>Choose one or more services</legend>
                    <p class="hint">Choose services from either Veterinary or Grooming for each appointment. For the same pet, book veterinary and grooming visits in opposite halves of the day: morning before 12:00 PM and afternoon from 12:00 PM. Appointments on your account cannot overlap.</p>
                    <div id="service-options" class="service-type-sections">
                      <?php foreach ($serviceGroups as $type => $groupServices): ?>
                        <?php if ($groupServices): ?>
                          <section class="service-type-group" data-service-group="<?= e($type) ?>">
                            <h4><?= $type === 'grooming' ? 'Grooming services' : 'Veterinary services' ?></h4>
                            <div class="svc">
                              <?php foreach ($groupServices as $service): ?>
                                <label class="service-option">
                                  <span class="service-option-copy">
                                    <span class="service-option-title"><input type="checkbox" name="service_ids[]" value="<?= (int) $service['service_id'] ?>" data-service-type="<?= e($type) ?>" data-name="<?= e($service['service_name']) ?>" data-price="<?= e((string) $service['base_price']) ?>" data-duration="<?= (int) $service['duration_minutes'] ?>" <?= in_array((int) $service['service_id'], $oldServiceIds, true) ? 'checked' : '' ?>><?= e($service['service_name']) ?></span>
                                    <small><?= e($service['description'] ?: 'Contact the clinic for service details.') ?></small>
                                    <small><?= (int) $service['duration_minutes'] ?> minutes each</small>
                                  </span>
                                  <strong>₱<?= number_format((float) $service['base_price'], 2) ?></strong>
                                </label>
                              <?php endforeach; ?>
                            </div>
                          </section>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                    <p class="service-selection-summary" id="service-selection-summary" aria-live="polite">Select services to see the combined time and estimated fee.</p>
                  </fieldset>
                  <div class="booking-step-actions booking-step-actions-next">
                    <span></span>
                    <button class="button" type="button" id="step1-next" disabled>Next: Date &amp; time</button>
                  </div>
                </section>

                <section class="booking-section" id="date-section" aria-labelledby="date-heading" hidden>
                    <div class="booking-section-heading"><span class="booking-section-number">02</span><div><h3 id="date-heading">Choose a date and time</h3><p>Live availability reflects the selected services, clinic schedules, existing appointments and any veterinary or grooming booking already made for your account that day.</p></div></div>
                  <div class="calendar-card">
                    <div class="calendar-toolbar">
                      <button class="button secondary small" type="button" id="month-previous" aria-label="Previous month">‹</button>
                      <strong id="calendar-month" aria-live="polite"></strong>
                      <button class="button secondary small" type="button" id="month-next" aria-label="Next month">›</button>
                    </div>
                    <div class="calendar-legend" aria-label="Calendar key">
                      <span><i class="legend-dot available"></i>Available</span><span><i class="legend-dot unavailable"></i>Fully booked</span>
                      <span><i class="legend-dot blocked"></i>Past / closed / not open yet</span><span><i class="legend-dot selected"></i>Selected</span>
                    </div>
                    <div class="calendar-weekdays" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
                    <div class="calendar-grid" id="calendar-grid" aria-live="polite"></div>
                    <p class="hint" id="calendar-message" role="status">Choose a pet and service to see live availability.</p>
                    <p class="selected-date-label" id="selected-date-label" aria-live="polite"></p>
                  </div>
                  <div class="booking-time-panel" id="time-section" hidden>
                    <div class="booking-subsection-heading"><span aria-hidden="true">TIME</span><div><h4>Choose an available time</h4><p id="time-date-label">Only available clinic times are shown.</p></div></div>
                    <div class="slots" id="time-slots" aria-live="polite"></div>
                    <p class="hint" id="time-message" role="status">Choose an available date first.</p>
                    <div class="assignment-card" id="assignment-card" aria-live="polite" hidden></div>
                  </div>
                  <div class="booking-step-actions">
                    <button class="button secondary" type="button" id="step2-back">Back</button>
                    <button class="button" type="button" id="step2-next" disabled>Next: Payment method</button>
                  </div>
                </section>

                <section class="booking-section" id="payment-section" aria-labelledby="payment-heading" hidden>
                  <div class="booking-section-heading"><span class="booking-section-number">03</span><div><h3 id="payment-heading">Payment preference</h3><p>This is only a preference. No payment is collected with this request.</p></div></div>
                  <div class="form-grid">
                    <label class="field">When would you prefer to pay?
                      <select name="payment_timing_preference" id="payment-timing-preference" required>
                        <option value="">Choose a preference</option>
                        <?php foreach (BOOKING_PAYMENT_TIMINGS as $timing): ?>
                          <option value="<?= e($timing) ?>" <?= ($old['payment_timing_preference'] ?? '') === $timing ? 'selected' : '' ?>><?= $timing === 'At clinic' ? 'Pay at the clinic' : 'Request advance payment instructions' ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label class="field">Preferred payment method
                      <select name="preferred_payment_method" id="preferred-payment-method" required>
                        <option value="">Choose a method</option>
                        <?php foreach (BOOKING_PAYMENT_METHODS as $method): ?>
                          <option value="<?= e($method) ?>" <?= ($old['preferred_payment_method'] ?? '') === $method ? 'selected' : '' ?>><?= e($method === 'Maya' ? 'Maya (PayMaya)' : $method) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>
                  <p class="hint">The clinic will confirm the final fee and, if requested, provide advance-payment instructions. This does not mark the appointment as paid.</p>
                  <label class="field booking-notes"><span id="booking-notes-label">Notes for the clinic (optional)</span><textarea name="notes" id="booking-notes" maxlength="255" placeholder="Symptoms, allergies or special handling"><?= e($old['notes'] ?? '') ?></textarea></label>
                  <div class="booking-step-actions">
                    <button class="button secondary" type="button" id="step3-back">Back</button>
                    <button class="button" type="button" id="step3-next" disabled>Next: Review</button>
                  </div>
                </section>

                <section class="booking-section" id="review-section" aria-labelledby="review-heading" hidden>
                  <div class="booking-section-heading"><span class="booking-section-number">04</span><div><h3 id="review-heading">Review your appointment</h3><p id="review-description">Your care team is assigned automatically and availability is rechecked before submission.</p></div></div>
                  <div class="review-card" id="review-summary" hidden>
                    <img id="review-pet-photo" src="../assets/images/pet.png" alt="">
                    <dl>
                      <div><dt>Pet</dt><dd id="review-pet"></dd></div>
                      <div><dt>Service</dt><dd id="review-service"></dd></div>
                      <div><dt>Date and time</dt><dd id="review-when"></dd></div>
                      <div><dt id="review-team-label">Veterinarian</dt><dd id="review-vet"></dd></div>
                      <div id="review-groomer-contact-row" hidden><dt>Groomer contact</dt><dd id="review-groomer-contact"></dd></div>
                      <div><dt>Estimated service fee</dt><dd id="review-fee"></dd></div>
                      <div><dt>Payment preference</dt><dd id="review-payment"></dd></div>
                      <div id="review-notes-row" hidden><dt>Notes for the clinic</dt><dd id="review-notes"></dd></div>
                    </dl>
                  </div>
                  <div class="booking-submit-row" id="booking-submit-row">
                    <p class="hint">Your request stays pending until the clinic confirms it.</p>
                    <button class="button secondary" type="button" id="step4-back">Back</button>
                    <button class="button" type="submit" id="booking-submit" disabled>Submit appointment request</button>
                  </div>
                </section>
              </form>
            <?php endif; ?>
          </div>
        </section>
        <script>
        (function(){
          var form=document.getElementById('book-form');
          if(!form)return;
          var petInputs=Array.prototype.slice.call(form.querySelectorAll('input[name="pet_id"]'));
          var serviceInputs=Array.prototype.slice.call(form.querySelectorAll('input[name="service_ids[]"]'));
          var progressItems=Array.prototype.slice.call(document.querySelectorAll('#booking-progress li'));
          var progressTrack=document.getElementById('booking-progress-track'),progressFill=document.getElementById('booking-progress-fill');
          var dateInput=document.getElementById('selected-date'),timeInput=document.getElementById('selected-time'),vetInput=document.getElementById('assigned-vet-id'),groomerInput=document.getElementById('assigned-groomer-id');
          var grid=document.getElementById('calendar-grid'),monthLabel=document.getElementById('calendar-month'),calendarMessage=document.getElementById('calendar-message');
          var slots=document.getElementById('time-slots'),timeMessage=document.getElementById('time-message');
          var dateSection=document.getElementById('date-section'),timeSection=document.getElementById('time-section');
          var paymentSection=document.getElementById('payment-section'),reviewSection=document.getElementById('review-section');
          var assignmentCard=document.getElementById('assignment-card'),reviewSummary=document.getElementById('review-summary');
          var submit=document.getElementById('booking-submit');
          var step1Next=document.getElementById('step1-next'),step2Next=document.getElementById('step2-next'),step3Next=document.getElementById('step3-next');
          var paymentTiming=document.getElementById('payment-timing-preference'),paymentMethod=document.getElementById('preferred-payment-method');
          var notesInput=document.getElementById('booking-notes');
          var currentStep=dateInput.value?2:1,month=new Date(<?= (int) date('Y') ?>,<?= (int) date('n') - 1 ?>,1),assignment=null,availabilityReady=false;
          var calendarRequest=0,slotsRequest=0,assignmentRequest=0;
          var now=new Date(),maxDate=new Date(<?= (int) substr(booking_last_bookable_date(), 0, 4) ?>,11,31);
          var monthMin=new Date(now.getFullYear(),now.getMonth(),1),monthMax=new Date(maxDate.getFullYear(),maxDate.getMonth(),1);
          function selectedPet(){return form.querySelector('input[name="pet_id"]:checked')}
          function selectedServices(){return serviceInputs.filter(function(input){return input.checked})}
          function serviceIds(){return selectedServices().map(function(input){return input.value})}
          function selectedServiceType(){var selected=selectedServices();return selected.length?selected[0].dataset.serviceType:''}
          function updateServiceTypeLock(){
            var chosen=selectedServices(),type=chosen.length?chosen[0].dataset.serviceType:'';
            chosen.forEach(function(input){if(input.dataset.serviceType!==type)input.checked=false});
            type=selectedServiceType();
            serviceInputs.forEach(function(input){input.disabled=!!type&&input.dataset.serviceType!==type});
            document.querySelectorAll('[data-service-group]').forEach(function(group){
              group.classList.toggle('is-locked',!!type&&group.dataset.serviceGroup!==type);
            });
          }
          function selectedPetId(){var pet=selectedPet();return pet?pet.value:''}
          function bookingReady(){return !!selectedPet()&&serviceIds().length>0}
          function petDetails(){
            var choice=selectedPet();
            if(!choice)return {name:'',photo:''};
            return {name:choice.dataset.petName||choice.value,photo:choice.dataset.photo||''};
          }
          function updateServiceSummary(){
            updateServiceTypeLock();
            var chosen=selectedServices(),duration=0,fee=0;
            chosen.forEach(function(service){duration+=Number(service.dataset.duration||0);fee+=Number(service.dataset.price||0)});
            var summary=document.getElementById('service-selection-summary');
            summary.textContent=chosen.length
              ?chosen.length+' service'+(chosen.length===1?'':'s')+' selected · '+duration+' minutes · ₱'+fee.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})
              :'Select services to see the combined time and estimated fee.';
          }
          function updateProgress(){
            var serviceComplete=bookingReady();
            var scheduleComplete=!!dateInput.value&&!!timeInput.value&&!!assignment;
            var paymentComplete=!!paymentTiming.value&&!!paymentMethod.value;
            var progressPercent=Math.round((currentStep-1)*100/3);
            step1Next.disabled=!serviceComplete;
            step2Next.disabled=!scheduleComplete;
            step3Next.disabled=!paymentComplete;
            submit.disabled=!(scheduleComplete&&paymentComplete);
            progressItems.forEach(function(item,index){
              var step=index+1;
              item.classList.toggle('on',step===currentStep);
              item.classList.toggle('done',step<currentStep);
              if(step===currentStep)item.setAttribute('aria-current','step');
              else item.removeAttribute('aria-current');
            });
            progressFill.style.width=progressPercent+'%';
            progressTrack.setAttribute('aria-valuenow',String(progressPercent));
          }
          function showStep(step,scroll){
            currentStep=step;
            var sections=[document.getElementById('pet-service-section'),dateSection,paymentSection,reviewSection];
            sections.forEach(function(section,index){section.hidden=index+1!==currentStep});
            updateProgress();
            if(scroll){
              sections[step-1].scrollIntoView({behavior:'smooth',block:'start'});
            }
          }
          function clearAssignment(){
            assignmentRequest++;
            assignment=null;vetInput.value='';groomerInput.value='';assignmentCard.replaceChildren();assignmentCard.hidden=true;
            reviewSummary.hidden=true;
            updateProgress();
          }
          function clearTimeSelection(){
            slotsRequest++;timeInput.value='';slots.replaceChildren();timeSection.hidden=true;
            timeMessage.textContent='Choose an available date first.';
            clearAssignment();
          }
          function clearDateSelection(){
            dateInput.value='';document.getElementById('selected-date-label').textContent='';
            clearTimeSelection();
          }
          function clearSelection(){
            calendarRequest++;clearDateSelection();grid.replaceChildren();
          }
          function refreshFlow(forceReload){
            var ready=bookingReady();
            if(!ready){
              if(availabilityReady||dateInput.value)clearSelection();
              if(currentStep!==1)showStep(1);
              availabilityReady=false;
              calendarMessage.textContent='Choose a pet and service to see live availability.';
              updateProgress();
              return Promise.resolve(null);
            }
            if(currentStep!==2)return Promise.resolve(null);
            var shouldLoad=forceReload||!availabilityReady;
            availabilityReady=true;updateProgress();
            return shouldLoad?loadCalendar():Promise.resolve(null);
          }
          petInputs.forEach(function(input){
            input.addEventListener('change',function(){
              clearSelection();showStep(1);refreshFlow(true);
            });
          });
          serviceInputs.forEach(function(input){
            input.addEventListener('change',function(){updateServiceSummary();clearSelection();showStep(1);refreshFlow(true)});
          });
          notesInput.addEventListener('input',updateProgress);
          function dateKey(y,m,d){return y+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0')}
          function monthKey(){return month.getFullYear()+'-'+String(month.getMonth()+1).padStart(2,'0')}
          async function getJson(params){
            var query=new URLSearchParams();
            Object.keys(params).forEach(function(key){
              var value=params[key];
              if(Array.isArray(value))value.forEach(function(item){query.append(key+'[]',item)});
              else query.append(key,String(value));
            });
            var response=await fetch('book_appointment.php?'+query.toString(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
            var data=await response.json();if(!response.ok)throw new Error(data.error||'Could not load appointment availability.');return data;
          }
          function renderCalendar(data){
            grid.replaceChildren();
            monthLabel.textContent=month.toLocaleDateString(undefined,{month:'long',year:'numeric'});
            var expiredSelection=dateInput.value&&data.days[dateInput.value]&&!data.days[dateInput.value].available;
            if(expiredSelection){clearDateSelection();showStep(2)}
            var first=new Date(month.getFullYear(),month.getMonth(),1),daysInMonth=new Date(month.getFullYear(),month.getMonth()+1,0).getDate();
            var offset=first.getDay();
            for(var blank=0;blank<offset;blank++){var spacer=document.createElement('span');spacer.className='calendar-blank';spacer.setAttribute('aria-hidden','true');grid.appendChild(spacer)}
            for(var dayNo=1;dayNo<=daysInMonth;dayNo++){
              var date=dateKey(month.getFullYear(),month.getMonth()+1,dayNo),state=data.days[date]||{selectable:false,available:false,holiday:false,reason:'Unavailable.'};
              var button=document.createElement('button');button.type='button';button.className='calendar-day';
              button.textContent=String(dayNo);button.dataset.date=date;
              if(state.available){button.classList.add('available');button.disabled=false;button.title='Available appointments';}
              else if(state.selectable){button.classList.add('unavailable');button.disabled=true;button.title=state.reason||'No availability';}
              else{button.classList.add('blocked');button.disabled=true;button.title=state.reason||'Cannot be booked';}
              button.setAttribute('aria-label',new Date(date+'T12:00:00').toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric'})+(state.available?', available':', '+(state.reason||'unavailable')));
              button.setAttribute('aria-pressed',date===dateInput.value?'true':'false');
              if(date===dateInput.value)button.classList.add('selected');
              if(state.available)button.addEventListener('click',function(){
                dateInput.value=this.dataset.date;clearTimeSelection();timeSection.hidden=false;
                document.getElementById('selected-date-label').textContent='Selected: '+new Date(this.dataset.date+'T12:00:00').toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric',year:'numeric'});
                updateProgress();
                renderCalendar(data);
                loadSlots();
              });
              grid.appendChild(button);
            }
            var prev=document.getElementById('month-previous'),following=document.getElementById('month-next');
            prev.disabled=month<=monthMin;following.disabled=month>=monthMax;
            calendarMessage.textContent=Object.keys(data.days).some(function(date){return data.days[date].available})
              ?'Green dates have an open appointment. Same-pet veterinary and grooming visits must be in opposite halves of the day; overlapping appointments are excluded.'
              :'No available times are published for the selected services in the displayed booking dates.';
            if(expiredSelection)calendarMessage.textContent='That date is no longer available. Choose another green date.';
          }
          async function loadCalendar(){
            if(!bookingReady()){calendarMessage.textContent='Choose a pet and service to see live availability.';grid.replaceChildren();return null}
            var request=++calendarRequest,requestedServices=serviceIds().join(',');
            calendarMessage.textContent='Checking available clinic times…';grid.replaceChildren();
            try{
              var data=await getJson({availability:1,month:monthKey(),pet_id:selectedPetId(),service_ids:serviceIds()});
              if(request!==calendarRequest||requestedServices!==serviceIds().join(','))return null;
              renderCalendar(data);
              return data;
            }catch(error){calendarMessage.textContent=error.message;console.error('Calendar availability:',error)}
            return null;
          }
          async function loadSlots(restoreTime){
            var requestedDate=dateInput.value,requestedServices=serviceIds().join(','),request=++slotsRequest;
            var wantedTime=restoreTime||'';
            slots.replaceChildren();timeInput.value='';timeSection.hidden=!requestedDate;
            clearAssignment();
            if(!requestedDate){timeMessage.textContent='Choose an available date first.';return}
            timeMessage.textContent='Checking available time slots…';
            document.getElementById('time-date-label').textContent=new Date(requestedDate+'T12:00:00').toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric',year:'numeric'});
            try{
              var data=await getJson({slots:1,date:requestedDate,pet_id:selectedPetId(),service_ids:serviceIds()});
              if(request!==slotsRequest||requestedDate!==dateInput.value||requestedServices!==serviceIds().join(','))return;
              if(!data.slots.length){
                dateInput.value='';document.getElementById('selected-date-label').textContent='';
                timeSection.hidden=true;timeMessage.textContent=data.message||'No available time remains for this date.';
                calendarMessage.textContent='That date is no longer available. Choose another green date.';
                updateProgress();loadCalendar();return;
              }
              var restoreButton=null;
              data.slots.forEach(function(slot){
                var button=document.createElement('button');button.type='button';button.textContent=slot.label;
                button.dataset.time=slot.time;button.setAttribute('aria-pressed','false');
                button.addEventListener('click',function(){
                  Array.prototype.forEach.call(slots.children,function(child){child.classList.remove('sel');child.setAttribute('aria-pressed','false')});
                  button.classList.add('sel');button.setAttribute('aria-pressed','true');timeInput.value=slot.time;
                  assignmentCard.hidden=false;assignmentCard.textContent='Checking appointment availability…';
                  updateProgress();loadAssignment();
                });
                slots.appendChild(button);
                if(slot.time===wantedTime)restoreButton=button;
              });
              timeMessage.textContent=wantedTime&&!restoreButton
                ?'That time is no longer available. Please choose another time.'
                :'Only available clinic times are shown.';
              if(restoreButton)restoreButton.click();
            }catch(error){timeMessage.textContent=error.message;console.error('Time slot availability:',error)}
          }
          async function loadAssignment(){
            var request=++assignmentRequest,requestedDate=dateInput.value,requestedTime=timeInput.value,requestedServices=serviceIds().join(',');
              assignment=null;vetInput.value='';groomerInput.value='';reviewSummary.hidden=true;assignmentCard.hidden=false;
            assignmentCard.textContent='Checking appointment availability…';
            updateProgress();
            try{
              var result=await getJson({preview:1,date:requestedDate,time:requestedTime,pet_id:selectedPetId(),service_ids:serviceIds()});
              if(request!==assignmentRequest||requestedDate!==dateInput.value||requestedTime!==timeInput.value||requestedServices!==serviceIds().join(','))return;
              assignment=result;
              vetInput.value=assignment.vet_id?String(assignment.vet_id):'';
              groomerInput.value=assignment.groomer_id?String(assignment.groomer_id):'';
              var isGrooming=selectedServiceType()==='grooming';
              var name=document.createElement('strong');name.textContent=isGrooming?assignment.groomer_name:assignment.vet_name;
              var detail=document.createElement('p');detail.textContent=assignment.date_label+' · '+assignment.time_label+' · '+assignment.duration+' minutes';
              var contact=document.createElement('p');contact.textContent=isGrooming
                ?(assignment.groomer_contact?'Contact: '+assignment.groomer_contact:'Contact number unavailable')
                :'';
              assignmentCard.replaceChildren(name,detail);
              if(isGrooming)assignmentCard.appendChild(contact);
              renderReview();reviewSummary.hidden=false;updateProgress();
            }catch(error){
              if(request!==assignmentRequest)return;
              var message=document.createElement('p');message.className='notice error';message.setAttribute('role','alert');message.textContent=error.message;
              assignmentCard.replaceChildren(message);console.error('Veterinarian assignment:',error);updateProgress();
            }
          }
          function renderReview(){
            if(!assignment)return;
            var petInfo=petDetails();
            var isGrooming=selectedServiceType()==='grooming';
            document.getElementById('review-pet').textContent=petInfo.name;
            document.getElementById('review-service').textContent=(assignment.service_names||[]).join(', ');
            document.getElementById('review-when').textContent=assignment.date_label+' · '+assignment.time_label;
            document.getElementById('review-team-label').textContent=isGrooming?'Groomer':'Veterinarian';
            document.getElementById('review-vet').textContent=isGrooming?assignment.groomer_name:assignment.vet_name;
            document.getElementById('review-groomer-contact-row').hidden=!isGrooming;
            document.getElementById('review-groomer-contact').textContent=assignment.groomer_contact||'Not listed';
            document.getElementById('review-description').textContent=isGrooming
              ?'The clinic will assign a groomer and recheck availability before confirming your request.'
              :'Your veterinarian is assigned automatically and rechecked before submission.';
            document.getElementById('review-fee').textContent='₱'+Number(assignment.fee).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
            var notes=notesInput.value.trim(),notesRow=document.getElementById('review-notes-row');
            document.getElementById('review-notes').textContent=notes;
            notesRow.hidden=!notes;
            renderPaymentReview();
            var photo=document.getElementById('review-pet-photo');
            photo.src=petInfo.photo||'../assets/images/pet.png';photo.alt=petInfo.photo?petInfo.name+' photo':'';
          }
          function renderPaymentReview(){
            var timing=document.getElementById('payment-timing-preference'),method=document.getElementById('preferred-payment-method');
            var timingLabel=timing.value==='At clinic'?'Pay at the clinic':timing.value==='Advance request'?'Request advance payment instructions':'Choose a payment preference';
            var methodLabel=method.value?method.value==='Maya'?'Maya (PayMaya)':method.value:'Choose a method';
            document.getElementById('review-payment').textContent=timingLabel+' · '+methodLabel;
          }
          paymentTiming.addEventListener('change',function(){renderPaymentReview();updateProgress()});
          paymentMethod.addEventListener('change',function(){renderPaymentReview();updateProgress()});
          step1Next.addEventListener('click',function(){
            if(!bookingReady())return;
            var restoreTime=timeInput.value;
            availabilityReady=false;showStep(2,true);
            refreshFlow(true).then(function(data){if(data&&dateInput.value)loadSlots(restoreTime)});
          });
          document.getElementById('step2-back').addEventListener('click',function(){showStep(1,true)});
          step2Next.addEventListener('click',function(){if(dateInput.value&&timeInput.value&&assignment)showStep(3,true)});
          document.getElementById('step3-back').addEventListener('click',function(){showStep(2,true);refreshAvailability()});
          step3Next.addEventListener('click',function(){
            if(!paymentTiming.reportValidity()||!paymentMethod.reportValidity())return;
            renderReview();showStep(4,true);
          });
          document.getElementById('step4-back').addEventListener('click',function(){showStep(3,true)});
          document.getElementById('month-previous').addEventListener('click',function(){if(month>monthMin){month=new Date(month.getFullYear(),month.getMonth()-1,1);loadCalendar()}});
          document.getElementById('month-next').addEventListener('click',function(){if(month<monthMax){month=new Date(month.getFullYear(),month.getMonth()+1,1);loadCalendar()}});
          form.addEventListener('submit',function(event){
            if(currentStep!==4){
              event.preventDefault();
            }else if(!bookingReady()){
              event.preventDefault();showStep(1,true);
            }else if(!dateInput.value||!timeInput.value||!assignment||(selectedServiceType()==='grooming'?!groomerInput.value:!vetInput.value)){
              event.preventDefault();showStep(2,true);
              timeMessage.textContent='Choose an available date and time before submitting.';
            }else if(!paymentTiming.value||!paymentMethod.value){
              event.preventDefault();showStep(3,true);
            }
          });
          function refreshAvailability(){
            var selectedTime=timeInput.value;
            loadCalendar().then(function(data){if(data&&dateInput.value)loadSlots(selectedTime)});
          }
          document.addEventListener('visibilitychange',function(){if(!document.hidden&&bookingReady()&&currentStep===2)refreshAvailability()});
          window.setInterval(function(){if(!document.hidden&&bookingReady()&&currentStep===2)refreshAvailability()},60000);
          updateServiceSummary();
          var savedDate=dateInput.value,savedTime=timeInput.value;
          showStep(currentStep);
          if(savedDate){
            var savedDateObject=new Date(savedDate+'T12:00:00');
            if(!isNaN(savedDateObject.getTime()))month=new Date(savedDateObject.getFullYear(),savedDateObject.getMonth(),1);
          }
          refreshFlow(true).then(function(data){
            if(!data||!savedDate)return;
            dateInput.value=savedDate;
            if(data.days[savedDate]&&data.days[savedDate].available){
              document.getElementById('selected-date-label').textContent='Selected: '+new Date(savedDate+'T12:00:00').toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric',year:'numeric'});
              renderCalendar(data);loadSlots(savedTime);
            }else{
              dateInput.value='';updateProgress();
            }
          });
        })();
        </script>
<?php client_footer($c);
