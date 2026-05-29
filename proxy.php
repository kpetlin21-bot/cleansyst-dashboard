<?php
// ============================================================
//  ThroneBaron API Proxy
// ============================================================

define('TB_API_KEY',  'de4oWlnBgj8|IumZlKGCOaIrlAI36RFdcHi4BwDMsU2SpiX9pzXy0aadc85b');
define('TB_PROJECT',  2);
define('TB_BASE_URL', 'https://api.thronebaron.com/v1');

// CORS — разрешаем запросы с любого домена (Netlify, Платрум и др.)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, apikey');
header('Content-Type: application/json; charset=utf-8');

// Preflight OPTIONS запрос от браузера — отвечаем 200 и выходим
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
//  Вспомогательная функция: выполнить запрос к ThroneBaron
// ============================================================
function tb_get(string $path, array $params = []): array {
    $url = TB_BASE_URL . $path;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => implode("\r\n", [
            'Authorization: Api-Key ' . TB_API_KEY,
            'Accept: application/json',
        ]),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? json_decode($body, true) : ['error' => 'Не удалось подключиться'];
}

// ============================================================
//  Пройти все страницы пагинации и собрать все записи
// ============================================================
function tb_get_all(string $path, array $params = []): array {
    $all = [];
    $params['limit'] = 250;  // максимум на страницу — 25000 задач за месяц
    $url = TB_BASE_URL . $path . '?' . http_build_query($params);
    $page = 0;
    while ($url && $page < 100) {  // до 25 000 задач
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => 'Authorization: Api-Key ' . TB_API_KEY . "\r\nAccept: application/json",
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) break;
        $json = json_decode($body, true);
        if (!isset($json['data'])) break;
        $all = array_merge($all, $json['data']);
        $url = $json['next_page_url'] ?? null;
        $page++;
    }
    return $all;
}

// ============================================================
//  Получить ВСЕ локации (с кешем на 1 час в файл)
// ============================================================
function get_all_locations(): array {
    $cacheFile = sys_get_temp_dir() . '/tb_locations_all.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        return json_decode(file_get_contents($cacheFile), true) ?: [];
    }
    $all = [];
    $url = TB_BASE_URL . '/locations?limit=250';
    $page = 0;
    while ($url && $page < 20) {
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => 'Authorization: Api-Key ' . TB_API_KEY . "\r\nAccept: application/json",
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if (!$body) break;
        $json = json_decode($body, true);
        if (!isset($json['data'])) break;
        $all = array_merge($all, $json['data']);
        $url = $json['next_page_url'] ?? null;
        $page++;
    }
    if (!empty($all)) file_put_contents($cacheFile, json_encode($all));
    return $all;
}

$action = $_GET['action'] ?? 'help';
$tz_msk = new DateTimeZone('Europe/Moscow');
$today  = (new DateTime('now', $tz_msk))->format('Y-m-d');

switch ($action) {

    // ----------------------------------------------------------
    //  ШАГ 1: узнать project_id
    //  Откройте в браузере: https://вашсайт.ru/proxy.php?action=projects
    // ----------------------------------------------------------
    case 'projects':
        echo json_encode(tb_get('/projects'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Смены за сегодня (или за переданную дату)
    //  ?action=shifts&date=2026-05-28
    // ----------------------------------------------------------
    case 'shifts':
        $date = $_GET['date'] ?? $today;
        $data = tb_get('/work-shifts', [
            'filter[date]'    => $date,
            'filter[project]' => TB_PROJECT,
        ]);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Отчёты по задачам (выполненные + пропущенные)
    //  ?action=tasks&date=today  или  ?action=tasks&date=2026-05-01,2026-05-28
    // ----------------------------------------------------------
    case 'tasks':
        $date   = $_GET['date'] ?? 'today';
        $done   = tb_get('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT, 'status' => 'done',   'limit' => 100]);
        $missed = tb_get('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT, 'status' => 'missed', 'limit' => 100]);
        $doneCnt   = count($done['data']   ?? []);
        $missedCnt = count($missed['data'] ?? []);
        $total     = $doneCnt + $missedCnt;
        echo json_encode([
            'date'       => $date,
            'total'      => $total,
            'done'       => $doneCnt,
            'missed'     => $missedCnt,
            'rate'       => $total > 0 ? round($doneCnt / $total * 100) : 0,
            'done_raw'   => $done['data']   ?? [],
            'missed_raw' => $missed['data'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Расписание задач на дату (план на день)
    //  ?action=schedule&date=2026-05-28
    // ----------------------------------------------------------
    case 'schedule':
        $date = $_GET['date'] ?? $today;
        $data = tb_get('/schedule/tasks', ['location' => TB_PROJECT]);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Список пользователей (штат)
    //  ?action=users
    // ----------------------------------------------------------
    case 'users':
        $data = tb_get('/users');
        $active = array_filter($data['data'] ?? [], function($u){ return $u['state'] === 'enabled'; });
        echo json_encode([
            'total'   => count($active),
            'users'   => array_values($active),
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Задачи за диапазон дат (неделя / месяц / вчера)
    //  ?action=period_tasks&date=2026-05-23,2026-05-29
    // ----------------------------------------------------------
    case 'period_tasks':
        set_time_limit(120);
        $date = $_GET['date'] ?? $today;

        // Кеш на 15 минут для периодных запросов
        $cacheKey  = 'tb_pt_' . TB_PROJECT . '_' . md5($date) . '.json';
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
            header('X-Cache: HIT');
            echo file_get_contents($cacheFile);
            break;
        }

        $allTasks  = tb_get_all('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT]);
        $totalCnt  = count($allTasks);
        $doneCnt   = 0; $inProgressCnt = 0; $missedCnt = 0;
        foreach ($allTasks as $t) {
            $st = $t['status'] ?? '';
            if      ($st === 'done')        $doneCnt++;
            elseif  ($st === 'in_progress') $inProgressCnt++;
            elseif  ($st === 'missed')      $missedCnt++;
        }
        $rate = $totalCnt > 0 ? round($doneCnt / $totalCnt * 100) : 0;

        $result = json_encode([
            'date'       => $date,
            'tasks'      => ['total'=>$totalCnt,'done'=>$doneCnt,'in_progress'=>$inProgressCnt,'missed'=>$missedCnt,'rate'=>$rate],
        ], JSON_UNESCAPED_UNICODE);
        file_put_contents($cacheFile, $result);
        echo $result;
        break;


    case 'dashboard':
        $tz   = new DateTimeZone('Europe/Moscow');
        $now  = new DateTime('now', $tz);
        $date = $_GET['date'] ?? $now->format('Y-m-d');

        // Все задачи за дату без фильтра статуса — даёт точный total
        $allTasks  = tb_get_all('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT]);

        // Если сегодня ещё нет задач (утро) — берём вчера
        $isYesterday = false;
        if (empty($allTasks) && !isset($_GET['date'])) {
            $yesterday = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
            $allTasks  = tb_get_all('/reports/tasks', ['date' => $yesterday, 'project' => TB_PROJECT]);
            $date      = $yesterday;
            $isYesterday = true;
        }

        // Подсчёт по статусам (как в ThroneBaron)
        // done       = выполнено
        // in_progress = в работе
        // available   = запланированы, ещё не начаты
        // pending     = ждут условий
        // missed      = пропущено
        $totalCnt      = count($allTasks);
        $doneCnt       = 0;
        $inProgressCnt = 0;
        $missedCnt     = 0;
        $availableCnt  = 0;
        $pendingCnt    = 0;
        foreach ($allTasks as $t) {
            $st = $t['status'] ?? '';
            if      ($st === 'done')        $doneCnt++;
            elseif  ($st === 'in_progress') $inProgressCnt++;
            elseif  ($st === 'missed')      $missedCnt++;
            elseif  ($st === 'available')   $availableCnt++;
            elseif  ($st === 'pending')     $pendingCnt++;
        }
        // % как в ThroneBaron: выполнено / всего
        $rate = $totalCnt > 0 ? round($doneCnt / $totalCnt * 100) : 0;

        // Почасовой разбор: реальное время выполнения из started_at (UTC→МСК +3)
        $hourly = array_fill(8, 13, 0); // часы 8..20
        foreach ($allTasks as $t) {
            if (($t['status'] ?? '') === 'done' && !empty($t['started_at'])) {
                $h = (int)date('H', strtotime($t['started_at']) + 3*3600);
                if ($h >= 8 && $h <= 20) $hourly[$h]++;
            }
        }
        ksort($hourly);

        // Смены — за ту же дату что и задачи
        $shifts     = tb_get('/work-shifts', ['filter[date]' => $date, 'filter[project]' => TB_PROJECT]);
        $shiftsData = $shifts['data'] ?? [];
        $staffTotal = count($shiftsData);
        $came = 0;
        $shiftsFormatted = [];
        foreach ($shiftsData as $s) {
            $hasStarted = !empty($s['started_at']);
            if ($hasStarted) $came++;
            $inTime  = $hasStarted ? date('H:i', strtotime($s['started_at']) + 3*3600) : null;
            $outTime = !empty($s['ended_at']) ? date('H:i', strtotime($s['ended_at']) + 3*3600) : null;
            $profile  = $s['user']['profile'] ?? [];
            $fullName = trim(($profile['last_name'] ?? '') . ' ' . ($profile['first_name'] ?? ''));
            $pos      = strtolower($profile['position'] ?? '');
            if (strpos($pos, 'двор') !== false) $role = 'Дворник';
            elseif (strpos($pos, 'убор') !== false) $role = 'Уборщица';
            elseif (strpos($pos, 'клин') !== false) $role = 'Клинер';
            else $role = $profile['position'] ?? '—';
            $shiftsFormatted[] = [
                'name' => $fullName ?: ($profile['first_name'] ?? '—'),
                'role' => $role,
                'in'   => $inTime,
                'out'  => $outTime,
                'came' => $hasStarted,
            ];
        }

        echo json_encode([
            'date'        => $date,
            'isYesterday' => $isYesterday,
            'tasks'  => [
                'total'       => $totalCnt,
                'done'        => $doneCnt,
                'in_progress' => $inProgressCnt,
                'missed'      => $missedCnt,
                'available'   => $availableCnt,
                'pending'     => $pendingCnt,
                'rate'        => $rate,
                'hourly'      => array_values($hourly),  // по часам 8..20
                'hourly_hours'=> array_keys($hourly),
            ],
            'staff'  => ['total' => $staffTotal, 'came' => $came, 'absent' => $staffTotal - $came],
            'shifts' => $shiftsFormatted,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Детализация задач по домам (кеш 15 мин)
    //  ?action=house_breakdown&date=2026-05-29
    // ----------------------------------------------------------
    case 'house_breakdown':
        set_time_limit(120);
        $date = $_GET['date'] ?? $today;
        $cacheFile = sys_get_temp_dir() . '/tb_hb_' . TB_PROJECT . '_' . $date . '.json';

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
            header('X-Cache: HIT');
            echo file_get_contents($cacheFile);
            break;
        }

        $allLocs  = get_all_locations();
        $rootLocs = array_filter($allLocs, function($l) {
            return $l['project_id'] == TB_PROJECT && $l['parent_id'] === null;
        });

        $houses = [];
        foreach ($rootLocs as $loc) {
            $locId   = $loc['id'];
            $locName = $loc['name'];
            $parts   = explode(' ', trim($locName), 2);
            $houseId = count($parts) > 1 ? $parts[1] : $locName;

            $done   = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$locId.'*','limit'=>250]);
            $missed = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$locId.'*','limit'=>250]);
            $dc = count($done['data'] ?? []);
            $mc = count($missed['data'] ?? []);
            $total = $dc + $mc;
            $rate  = $total > 0 ? round($dc/$total*100) : 0;
            $status = $rate>=90 ? 'ok' : ($rate>=70 ? 'warn' : 'crit');

            $houses[] = ['id'=>$houseId,'label'=>$locName,'locationId'=>$locId,'done'=>$dc,'missed'=>$mc,'total'=>$total,'pct'=>$rate,'status'=>$status];
        }

        usort($houses, function($a,$b){ return $a['pct'] - $b['pct']; });
        $result = json_encode(['date'=>$date,'houses'=>$houses,'cached'=>false], JSON_UNESCAPED_UNICODE);
        file_put_contents($cacheFile, $result);
        echo $result;
        break;

    // ----------------------------------------------------------
    //  Детализация одного дома: МОП (по секциям) + ПДТ
    //  ?action=house_detail&location_id=1&date=2026-05-29
    //
    //  Логика:
    //    Дом (wildcard) = МОП + ПДТ + прочее
    //    МОП = сумма задач по всем секциям (каждая с wildcard)
    //    ПДТ = Дом − МОП  (то что не попало в секции)
    // ----------------------------------------------------------
    case 'house_detail':
        set_time_limit(120);
        $locId = (int)($_GET['location_id'] ?? 0);
        if (!$locId) { echo json_encode(['error'=>'location_id required']); break; }
        $date = $_GET['date'] ?? $today;

        $allLocs  = get_all_locations();

        // Секции (прямые дети дома)
        $sections = array_values(array_filter($allLocs, function($l) use ($locId) {
            return (int)$l['parent_id'] === $locId;
        }));

        // Всего по дому (wildcard = все вложенные локации)
        $houseD    = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$locId.'*','limit'=>250]);
        $houseM    = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$locId.'*','limit'=>250]);
        $houseDone = count($houseD['data'] ?? []);
        $houseMiss = count($houseM['data'] ?? []);
        $houseTot  = $houseDone + $houseMiss;

        // МОП = задачи по каждой секции (с wildcard = включая этажи)
        $mopZones = []; $mopDone = 0; $mopMiss = 0;
        foreach ($sections as $sec) {
            $sd = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$sec['id'].'*','limit'=>250]);
            $sm = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$sec['id'].'*','limit'=>250]);
            $d  = count($sd['data'] ?? []);
            $m  = count($sm['data'] ?? []);
            $mopDone += $d; $mopMiss += $m;
            $t  = $d + $m;
            $mopZones[] = ['name'=>$sec['name'],'id'=>$sec['id'],'done'=>$d,'missed'=>$m,'total'=>$t,'pct'=> $t>0?round($d/$t*100):0];
        }
        $mopTot = $mopDone + $mopMiss;

        // ПДТ = то что не вошло в секции (придомовая территория)
        $pdtDone = max(0, $houseDone - $mopDone);
        $pdtMiss = max(0, $houseMiss - $mopMiss);
        $pdtTot  = $pdtDone + $pdtMiss;

        echo json_encode([
            'date'   => $date,
            'locId'  => $locId,
            'total'  => ['done'=>$houseDone,'missed'=>$houseMiss,'total'=>$houseTot],
            'mop'    => ['done'=>$mopDone,'missed'=>$mopMiss,'total'=>$mopTot,'pct'=>$mopTot>0?round($mopDone/$mopTot*100):0,'zones'=>$mopZones],
            'pdt'    => ['done'=>$pdtDone,'missed'=>$pdtMiss,'total'=>$pdtTot,'pct'=>$pdtTot>0?round($pdtDone/$pdtTot*100):0],
        ], JSON_UNESCAPED_UNICODE);
        break;


    case 'teams':
        $data = tb_get('/teams', ['project' => TB_PROJECT]);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Диагностика: структура ответа API задач
    //  ?action=task_debug&date=2026-05-29
    // ----------------------------------------------------------
    case 'task_debug':
        $date = $_GET['date'] ?? $today;
        $out  = [];

        // Смотрим структуру первой задачи (без фильтра)
        $r = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'limit'=>3]);
        $out['sample_tasks'] = $r['data'] ?? [];

        // Пробуем разные статусы
        foreach (['done','missed','in_progress'] as $st) {
            $r = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>$st,'limit'=>1]);
            $out['status_'.$st] = [
                'data_count'    => count($r['data'] ?? []),
                'has_next_page' => !empty($r['next_page_url']),
                'sample'        => $r['data'][0] ?? null,
            ];
        }
        // Всего без фильтра (считаем все страницы)
        $all = tb_get_all('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT]);
        $statusCounts = [];
        foreach ($all as $t) {
            $st = $t['status'] ?? $t['state'] ?? '(no_status_field)';
            $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
        }
        $out['total_all_pages']  = count($all);
        $out['status_breakdown'] = $statusCounts;

        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;



    // ----------------------------------------------------------
    //  Локации
    //  ?action=locations
    // ----------------------------------------------------------
    case 'locations':
        $data = tb_get('/locations');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Статистика по домам (командам) за дату
    //  ?action=house_stats&date=2026-05-29
    // ----------------------------------------------------------
    case 'house_stats':
        $tz   = new DateTimeZone('Europe/Moscow');
        $now  = new DateTime('now', $tz);
        $date = $_GET['date'] ?? $now->format('Y-m-d');

        // Получаем все команды проекта
        $teamsData = tb_get('/teams', ['project' => TB_PROJECT]);
        $teams = $teamsData['data'] ?? [];

        // Для каждой команды получаем done/missed задачи по локации
        // Команды named "МОП 28к2" → извлекаем "28к2"
        $houseStats = [];
        foreach ($teams as $team) {
            $tname = $team['name'] ?? '';
            // Извлекаем номер дома из названия команды
            preg_match('/(\d+[\/к]\d+|\d+к\d+|\d+\/\d+)/ui', $tname, $m);
            $houseNum = $m[0] ?? $tname;

            // Задачи команды — фильтр по project, статус done/missed за дату
            // ThroneBaron: assignee t{id} — но в reports нет фильтра по команде
            // Используем location если совпадает, иначе показываем команду как есть
            $houseStats[] = [
                'teamId'   => $team['id'],
                'teamName' => $tname,
                'house'    => $houseNum,
            ];
        }

        echo json_encode([
            'date'   => $date,
            'teams'  => $houseStats,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Помощь: список доступных action
    // ----------------------------------------------------------
    default:
        echo json_encode([
            'endpoints' => [
                '?action=projects'              => 'Список проектов (найти project_id)',
                '?action=dashboard&date=today'  => 'Все KPI за дату — основной запрос дашборда',
                '?action=teams'                 => 'Команды проекта (= дома ЖК)',
                '?action=locations'             => 'Локации проекта',
                '?action=house_stats'           => 'Статистика по командам/домам',
                '?action=shifts&date=2026-05-28'=> 'Смены за дату',
                '?action=tasks&date=today'      => 'Задачи: выполнено / пропущено',
                '?action=users'                 => 'Список активных сотрудников',
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? json_decode($body, true) : ['error' => 'Не удалось подключиться'];
}

// ============================================================
//  Пройти все страницы пагинации и собрать все записи
// ============================================================
function tb_get_all(string $path, array $params = []): array {
    $all = [];
    $params['limit'] = 250;  // максимум на страницу — 25000 задач за месяц
    $url = TB_BASE_URL . $path . '?' . http_build_query($params);
    $page = 0;
    while ($url && $page < 100) {  // до 25 000 задач
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => 'Authorization: Api-Key ' . TB_API_KEY . "\r\nAccept: application/json",
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) break;
        $json = json_decode($body, true);
        if (!isset($json['data'])) break;
        $all = array_merge($all, $json['data']);
        $url = $json['next_page_url'] ?? null;
        $page++;
    }
    return $all;
}

// ============================================================
//  Получить ВСЕ локации (с кешем на 1 час в файл)
// ============================================================
function get_all_locations(): array {
    $cacheFile = sys_get_temp_dir() . '/tb_locations_all.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        return json_decode(file_get_contents($cacheFile), true) ?: [];
    }
    $all = [];
    $url = TB_BASE_URL . '/locations?limit=250';
    $page = 0;
    while ($url && $page < 20) {
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => 'Authorization: Api-Key ' . TB_API_KEY . "\r\nAccept: application/json",
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if (!$body) break;
        $json = json_decode($body, true);
        if (!isset($json['data'])) break;
        $all = array_merge($all, $json['data']);
        $url = $json['next_page_url'] ?? null;
        $page++;
    }
    if (!empty($all)) file_put_contents($cacheFile, json_encode($all));
    return $all;
}

$action = $_GET['action'] ?? 'help';
$tz_msk = new DateTimeZone('Europe/Moscow');
$today  = (new DateTime('now', $tz_msk))->format('Y-m-d');

switch ($action) {

    // ----------------------------------------------------------
    //  ШАГ 1: узнать project_id
    //  Откройте в браузере: https://вашсайт.ru/proxy.php?action=projects
    // ----------------------------------------------------------
    case 'projects':
        echo json_encode(tb_get('/projects'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Смены за сегодня (или за переданную дату)
    //  ?action=shifts&date=2026-05-28
    // ----------------------------------------------------------
    case 'shifts':
        $date = $_GET['date'] ?? $today;
        $data = tb_get('/work-shifts', [
            'filter[date]'    => $date,
            'filter[project]' => TB_PROJECT,
        ]);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Отчёты по задачам (выполненные + пропущенные)
    //  ?action=tasks&date=today  или  ?action=tasks&date=2026-05-01,2026-05-28
    // ----------------------------------------------------------
    case 'tasks':
        $date   = $_GET['date'] ?? 'today';
        $done   = tb_get('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT, 'status' => 'done',   'limit' => 100]);
        $missed = tb_get('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT, 'status' => 'missed', 'limit' => 100]);
        $doneCnt   = count($done['data']   ?? []);
        $missedCnt = count($missed['data'] ?? []);
        $total     = $doneCnt + $missedCnt;
        echo json_encode([
            'date'       => $date,
            'total'      => $total,
            'done'       => $doneCnt,
            'missed'     => $missedCnt,
            'rate'       => $total > 0 ? round($doneCnt / $total * 100) : 0,
            'done_raw'   => $done['data']   ?? [],
            'missed_raw' => $missed['data'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Расписание задач на дату (план на день)
    //  ?action=schedule&date=2026-05-28
    // ----------------------------------------------------------
    case 'schedule':
        $date = $_GET['date'] ?? $today;
        $data = tb_get('/schedule/tasks', ['location' => TB_PROJECT]);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Список пользователей (штат)
    //  ?action=users
    // ----------------------------------------------------------
    case 'users':
        $data = tb_get('/users');
        $active = array_filter($data['data'] ?? [], function($u){ return $u['state'] === 'enabled'; });
        echo json_encode([
            'total'   => count($active),
            'users'   => array_values($active),
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Задачи за диапазон дат (неделя / месяц / вчера)
    //  ?action=period_tasks&date=2026-05-23,2026-05-29
    // ----------------------------------------------------------
    case 'period_tasks':
        set_time_limit(120);
        $date = $_GET['date'] ?? $today;

        // Кеш на 15 минут для периодных запросов
        $cacheKey  = 'tb_pt_' . TB_PROJECT . '_' . md5($date) . '.json';
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
            header('X-Cache: HIT');
            echo file_get_contents($cacheFile);
            break;
        }

        $allTasks  = tb_get_all('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT]);
        $totalCnt  = count($allTasks);
        $doneCnt   = 0; $inProgressCnt = 0; $missedCnt = 0;
        foreach ($allTasks as $t) {
            $st = $t['status'] ?? '';
            if      ($st === 'done')        $doneCnt++;
            elseif  ($st === 'in_progress') $inProgressCnt++;
            elseif  ($st === 'missed')      $missedCnt++;
        }
        $rate = $totalCnt > 0 ? round($doneCnt / $totalCnt * 100) : 0;

        $result = json_encode([
            'date'       => $date,
            'tasks'      => ['total'=>$totalCnt,'done'=>$doneCnt,'in_progress'=>$inProgressCnt,'missed'=>$missedCnt,'rate'=>$rate],
        ], JSON_UNESCAPED_UNICODE);
        file_put_contents($cacheFile, $result);
        echo $result;
        break;


    case 'dashboard':
        $tz   = new DateTimeZone('Europe/Moscow');
        $now  = new DateTime('now', $tz);
        $date = $_GET['date'] ?? $now->format('Y-m-d');

        // Все задачи за дату без фильтра статуса — даёт точный total
        $allTasks  = tb_get_all('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT]);

        // Если сегодня ещё нет задач (утро) — берём вчера
        $isYesterday = false;
        if (empty($allTasks) && !isset($_GET['date'])) {
            $yesterday = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
            $allTasks  = tb_get_all('/reports/tasks', ['date' => $yesterday, 'project' => TB_PROJECT]);
            $date      = $yesterday;
            $isYesterday = true;
        }

        // Подсчёт по статусам (как в ThroneBaron)
        // done       = выполнено
        // in_progress = в работе
        // available   = запланированы, ещё не начаты
        // pending     = ждут условий
        // missed      = пропущено
        $totalCnt      = count($allTasks);
        $doneCnt       = 0;
        $inProgressCnt = 0;
        $missedCnt     = 0;
        $availableCnt  = 0;
        $pendingCnt    = 0;
        foreach ($allTasks as $t) {
            $st = $t['status'] ?? '';
            if      ($st === 'done')        $doneCnt++;
            elseif  ($st === 'in_progress') $inProgressCnt++;
            elseif  ($st === 'missed')      $missedCnt++;
            elseif  ($st === 'available')   $availableCnt++;
            elseif  ($st === 'pending')     $pendingCnt++;
        }
        // % как в ThroneBaron: выполнено / всего
        $rate = $totalCnt > 0 ? round($doneCnt / $totalCnt * 100) : 0;

        // Почасовой разбор: реальное время выполнения из started_at (UTC→МСК +3)
        $hourly = array_fill(8, 13, 0); // часы 8..20
        foreach ($allTasks as $t) {
            if (($t['status'] ?? '') === 'done' && !empty($t['started_at'])) {
                $h = (int)date('H', strtotime($t['started_at']) + 3*3600);
                if ($h >= 8 && $h <= 20) $hourly[$h]++;
            }
        }
        ksort($hourly);

        // Смены — за ту же дату что и задачи
        $shifts     = tb_get('/work-shifts', ['filter[date]' => $date, 'filter[project]' => TB_PROJECT]);
        $shiftsData = $shifts['data'] ?? [];
        $staffTotal = count($shiftsData);
        $came = 0;
        $shiftsFormatted = [];
        foreach ($shiftsData as $s) {
            $hasStarted = !empty($s['started_at']);
            if ($hasStarted) $came++;
            $inTime  = $hasStarted ? date('H:i', strtotime($s['started_at']) + 3*3600) : null;
            $outTime = !empty($s['ended_at']) ? date('H:i', strtotime($s['ended_at']) + 3*3600) : null;
            $profile  = $s['user']['profile'] ?? [];
            $fullName = trim(($profile['last_name'] ?? '') . ' ' . ($profile['first_name'] ?? ''));
            $pos      = strtolower($profile['position'] ?? '');
            if (strpos($pos, 'двор') !== false) $role = 'Дворник';
            elseif (strpos($pos, 'убор') !== false) $role = 'Уборщица';
            elseif (strpos($pos, 'клин') !== false) $role = 'Клинер';
            else $role = $profile['position'] ?? '—';
            $shiftsFormatted[] = [
                'name' => $fullName ?: ($profile['first_name'] ?? '—'),
                'role' => $role,
                'in'   => $inTime,
                'out'  => $outTime,
                'came' => $hasStarted,
            ];
        }

        echo json_encode([
            'date'        => $date,
            'isYesterday' => $isYesterday,
            'tasks'  => [
                'total'       => $totalCnt,
                'done'        => $doneCnt,
                'in_progress' => $inProgressCnt,
                'missed'      => $missedCnt,
                'available'   => $availableCnt,
                'pending'     => $pendingCnt,
                'rate'        => $rate,
                'hourly'      => array_values($hourly),  // по часам 8..20
                'hourly_hours'=> array_keys($hourly),
            ],
            'staff'  => ['total' => $staffTotal, 'came' => $came, 'absent' => $staffTotal - $came],
            'shifts' => $shiftsFormatted,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Детализация задач по домам (кеш 15 мин)
    //  ?action=house_breakdown&date=2026-05-29
    // ----------------------------------------------------------
    case 'house_breakdown':
        set_time_limit(120);
        $date = $_GET['date'] ?? $today;
        $cacheFile = sys_get_temp_dir() . '/tb_hb_' . TB_PROJECT . '_' . $date . '.json';

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
            header('X-Cache: HIT');
            echo file_get_contents($cacheFile);
            break;
        }

        $allLocs  = get_all_locations();
        $rootLocs = array_filter($allLocs, function($l) {
            return $l['project_id'] == TB_PROJECT && $l['parent_id'] === null;
        });

        $houses = [];
        foreach ($rootLocs as $loc) {
            $locId   = $loc['id'];
            $locName = $loc['name'];
            $parts   = explode(' ', trim($locName), 2);
            $houseId = count($parts) > 1 ? $parts[1] : $locName;

            $done   = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$locId.'*','limit'=>250]);
            $missed = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$locId.'*','limit'=>250]);
            $dc = count($done['data'] ?? []);
            $mc = count($missed['data'] ?? []);
            $total = $dc + $mc;
            $rate  = $total > 0 ? round($dc/$total*100) : 0;
            $status = $rate>=90 ? 'ok' : ($rate>=70 ? 'warn' : 'crit');

            $houses[] = ['id'=>$houseId,'label'=>$locName,'locationId'=>$locId,'done'=>$dc,'missed'=>$mc,'total'=>$total,'pct'=>$rate,'status'=>$status];
        }

        usort($houses, function($a,$b){ return $a['pct'] - $b['pct']; });
        $result = json_encode(['date'=>$date,'houses'=>$houses,'cached'=>false], JSON_UNESCAPED_UNICODE);
        file_put_contents($cacheFile, $result);
        echo $result;
        break;

    // ----------------------------------------------------------
    //  Детализация одного дома: МОП (по секциям) + ПДТ
    //  ?action=house_detail&location_id=1&date=2026-05-29
    //
    //  Логика:
    //    Дом (wildcard) = МОП + ПДТ + прочее
    //    МОП = сумма задач по всем секциям (каждая с wildcard)
    //    ПДТ = Дом − МОП  (то что не попало в секции)
    // ----------------------------------------------------------
    case 'house_detail':
        set_time_limit(120);
        $locId = (int)($_GET['location_id'] ?? 0);
        if (!$locId) { echo json_encode(['error'=>'location_id required']); break; }
        $date = $_GET['date'] ?? $today;

        $allLocs  = get_all_locations();

        // Секции (прямые дети дома)
        $sections = array_values(array_filter($allLocs, function($l) use ($locId) {
            return (int)$l['parent_id'] === $locId;
        }));

        // Всего по дому (wildcard = все вложенные локации)
        $houseD    = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$locId.'*','limit'=>250]);
        $houseM    = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$locId.'*','limit'=>250]);
        $houseDone = count($houseD['data'] ?? []);
        $houseMiss = count($houseM['data'] ?? []);
        $houseTot  = $houseDone + $houseMiss;

        // МОП = задачи по каждой секции (с wildcard = включая этажи)
        $mopZones = []; $mopDone = 0; $mopMiss = 0;
        foreach ($sections as $sec) {
            $sd = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$sec['id'].'*','limit'=>250]);
            $sm = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$sec['id'].'*','limit'=>250]);
            $d  = count($sd['data'] ?? []);
            $m  = count($sm['data'] ?? []);
            $mopDone += $d; $mopMiss += $m;
            $t  = $d + $m;
            $mopZones[] = ['name'=>$sec['name'],'id'=>$sec['id'],'done'=>$d,'missed'=>$m,'total'=>$t,'pct'=> $t>0?round($d/$t*100):0];
        }
        $mopTot = $mopDone + $mopMiss;

        // ПДТ = то что не вошло в секции (придомовая территория)
        $pdtDone = max(0, $houseDone - $mopDone);
        $pdtMiss = max(0, $houseMiss - $mopMiss);
        $pdtTot  = $pdtDone + $pdtMiss;

        echo json_encode([
            'date'   => $date,
            'locId'  => $locId,
            'total'  => ['done'=>$houseDone,'missed'=>$houseMiss,'total'=>$houseTot],
            'mop'    => ['done'=>$mopDone,'missed'=>$mopMiss,'total'=>$mopTot,'pct'=>$mopTot>0?round($mopDone/$mopTot*100):0,'zones'=>$mopZones],
            'pdt'    => ['done'=>$pdtDone,'missed'=>$pdtMiss,'total'=>$pdtTot,'pct'=>$pdtTot>0?round($pdtDone/$pdtTot*100):0],
        ], JSON_UNESCAPED_UNICODE);
        break;


    case 'teams':
        $data = tb_get('/teams', ['project' => TB_PROJECT]);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Диагностика: структура ответа API задач
    //  ?action=task_debug&date=2026-05-29
    // ----------------------------------------------------------
    case 'task_debug':
        $date = $_GET['date'] ?? $today;
        $out  = [];

        // Смотрим структуру первой задачи (без фильтра)
        $r = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'limit'=>3]);
        $out['sample_tasks'] = $r['data'] ?? [];

        // Пробуем разные статусы
        foreach (['done','missed','in_progress'] as $st) {
            $r = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>$st,'limit'=>1]);
            $out['status_'.$st] = [
                'data_count'    => count($r['data'] ?? []),
                'has_next_page' => !empty($r['next_page_url']),
                'sample'        => $r['data'][0] ?? null,
            ];
        }
        // Всего без фильтра (считаем все страницы)
        $all = tb_get_all('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT]);
        $statusCounts = [];
        foreach ($all as $t) {
            $st = $t['status'] ?? $t['state'] ?? '(no_status_field)';
            $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
        }
        $out['total_all_pages']  = count($all);
        $out['status_breakdown'] = $statusCounts;

        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;



    // ----------------------------------------------------------
    //  Локации
    //  ?action=locations
    // ----------------------------------------------------------
    case 'locations':
        $data = tb_get('/locations');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Статистика по домам (командам) за дату
    //  ?action=house_stats&date=2026-05-29
    // ----------------------------------------------------------
    case 'house_stats':
        $tz   = new DateTimeZone('Europe/Moscow');
        $now  = new DateTime('now', $tz);
        $date = $_GET['date'] ?? $now->format('Y-m-d');

        // Получаем все команды проекта
        $teamsData = tb_get('/teams', ['project' => TB_PROJECT]);
        $teams = $teamsData['data'] ?? [];

        // Для каждой команды получаем done/missed задачи по локации
        // Команды named "МОП 28к2" → извлекаем "28к2"
        $houseStats = [];
        foreach ($teams as $team) {
            $tname = $team['name'] ?? '';
            // Извлекаем номер дома из названия команды
            preg_match('/(\d+[\/к]\d+|\d+к\d+|\d+\/\d+)/ui', $tname, $m);
            $houseNum = $m[0] ?? $tname;

            // Задачи команды — фильтр по project, статус done/missed за дату
            // ThroneBaron: assignee t{id} — но в reports нет фильтра по команде
            // Используем location если совпадает, иначе показываем команду как есть
            $houseStats[] = [
                'teamId'   => $team['id'],
                'teamName' => $tname,
                'house'    => $houseNum,
            ];
        }

        echo json_encode([
            'date'   => $date,
            'teams'  => $houseStats,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Помощь: список доступных action
    // ----------------------------------------------------------
    default:
        echo json_encode([
            'endpoints' => [
                '?action=projects'              => 'Список проектов (найти project_id)',
                '?action=dashboard&date=today'  => 'Все KPI за дату — основной запрос дашборда',
                '?action=teams'                 => 'Команды проекта (= дома ЖК)',
                '?action=locations'             => 'Локации проекта',
                '?action=house_stats'           => 'Статистика по командам/домам',
                '?action=shifts&date=2026-05-28'=> 'Смены за дату',
                '?action=tasks&date=today'      => 'Задачи: выполнено / пропущено',
                '?action=users'                 => 'Список активных сотрудников',
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? json_decode($body, true) : ['error' => 'Не удалось подключиться'];
}

// ============================================================
//  Пройти все страницы пагинации и собрать все записи
// ============================================================
function tb_get_all(string $path, array $params = []): array {
    $all = [];
    $params['limit'] = 100;
    $url = TB_BASE_URL . $path . '?' . http_build_query($params);
    $page = 0;
    while ($url && $page < 20) {
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => 'Authorization: Api-Key ' . TB_API_KEY . "\r\nAccept: application/json",
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) break;
        $json = json_decode($body, true);
        if (!isset($json['data'])) break;
        $all = array_merge($all, $json['data']);
        $url = $json['next_page_url'] ?? null;
        $page++;
    }
    return $all;
}

// ============================================================
//  Получить ВСЕ локации (с кешем на 1 час в файл)
// ============================================================
function get_all_locations(): array {
    $cacheFile = sys_get_temp_dir() . '/tb_locations_all.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        return json_decode(file_get_contents($cacheFile), true) ?: [];
    }
    $all = [];
    $url = TB_BASE_URL . '/locations?limit=250';
    $page = 0;
    while ($url && $page < 20) {
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => 'Authorization: Api-Key ' . TB_API_KEY . "\r\nAccept: application/json",
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if (!$body) break;
        $json = json_decode($body, true);
        if (!isset($json['data'])) break;
        $all = array_merge($all, $json['data']);
        $url = $json['next_page_url'] ?? null;
        $page++;
    }
    if (!empty($all)) file_put_contents($cacheFile, json_encode($all));
    return $all;
}

$action = $_GET['action'] ?? 'help';
$tz_msk = new DateTimeZone('Europe/Moscow');
$today  = (new DateTime('now', $tz_msk))->format('Y-m-d');

switch ($action) {

    // ----------------------------------------------------------
    //  ШАГ 1: узнать project_id
    //  Откройте в браузере: https://вашсайт.ru/proxy.php?action=projects
    // ----------------------------------------------------------
    case 'projects':
        echo json_encode(tb_get('/projects'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Смены за сегодня (или за переданную дату)
    //  ?action=shifts&date=2026-05-28
    // ----------------------------------------------------------
    case 'shifts':
        $date = $_GET['date'] ?? $today;
        $data = tb_get('/work-shifts', [
            'filter[date]'    => $date,
            'filter[project]' => TB_PROJECT,
        ]);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Отчёты по задачам (выполненные + пропущенные)
    //  ?action=tasks&date=today  или  ?action=tasks&date=2026-05-01,2026-05-28
    // ----------------------------------------------------------
    case 'tasks':
        $date   = $_GET['date'] ?? 'today';
        $done   = tb_get('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT, 'status' => 'done',   'limit' => 100]);
        $missed = tb_get('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT, 'status' => 'missed', 'limit' => 100]);
        $doneCnt   = count($done['data']   ?? []);
        $missedCnt = count($missed['data'] ?? []);
        $total     = $doneCnt + $missedCnt;
        echo json_encode([
            'date'       => $date,
            'total'      => $total,
            'done'       => $doneCnt,
            'missed'     => $missedCnt,
            'rate'       => $total > 0 ? round($doneCnt / $total * 100) : 0,
            'done_raw'   => $done['data']   ?? [],
            'missed_raw' => $missed['data'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Расписание задач на дату (план на день)
    //  ?action=schedule&date=2026-05-28
    // ----------------------------------------------------------
    case 'schedule':
        $date = $_GET['date'] ?? $today;
        $data = tb_get('/schedule/tasks', ['location' => TB_PROJECT]);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Список пользователей (штат)
    //  ?action=users
    // ----------------------------------------------------------
    case 'users':
        $data = tb_get('/users');
        $active = array_filter($data['data'] ?? [], function($u){ return $u['state'] === 'enabled'; });
        echo json_encode([
            'total'   => count($active),
            'users'   => array_values($active),
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Задачи за диапазон дат (неделя / месяц / вчера)
    //  ?action=period_tasks&date=2026-05-23,2026-05-29
    // ----------------------------------------------------------
    case 'period_tasks':
        set_time_limit(120);
        $date = $_GET['date'] ?? $today;

        // Кеш на 15 минут для периодных запросов
        $cacheKey  = 'tb_pt_' . TB_PROJECT . '_' . md5($date) . '.json';
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
            header('X-Cache: HIT');
            echo file_get_contents($cacheFile);
            break;
        }

        $allTasks  = tb_get_all('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT]);
        $totalCnt  = count($allTasks);
        $doneCnt   = 0; $inProgressCnt = 0; $missedCnt = 0;
        foreach ($allTasks as $t) {
            $st = $t['status'] ?? '';
            if      ($st === 'done')        $doneCnt++;
            elseif  ($st === 'in_progress') $inProgressCnt++;
            elseif  ($st === 'missed')      $missedCnt++;
        }
        $rate = $totalCnt > 0 ? round($doneCnt / $totalCnt * 100) : 0;

        $result = json_encode([
            'date'       => $date,
            'tasks'      => ['total'=>$totalCnt,'done'=>$doneCnt,'in_progress'=>$inProgressCnt,'missed'=>$missedCnt,'rate'=>$rate],
        ], JSON_UNESCAPED_UNICODE);
        file_put_contents($cacheFile, $result);
        echo $result;
        break;


    case 'dashboard':
        $tz   = new DateTimeZone('Europe/Moscow');
        $now  = new DateTime('now', $tz);
        $date = $_GET['date'] ?? $now->format('Y-m-d');

        // Все задачи за дату без фильтра статуса — даёт точный total
        $allTasks  = tb_get_all('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT]);

        // Если сегодня ещё нет задач (утро) — берём вчера
        $isYesterday = false;
        if (empty($allTasks) && !isset($_GET['date'])) {
            $yesterday = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
            $allTasks  = tb_get_all('/reports/tasks', ['date' => $yesterday, 'project' => TB_PROJECT]);
            $date      = $yesterday;
            $isYesterday = true;
        }

        // Подсчёт по статусам (как в ThroneBaron)
        // done       = выполнено
        // in_progress = в работе
        // available   = запланированы, ещё не начаты
        // pending     = ждут условий
        // missed      = пропущено
        $totalCnt      = count($allTasks);
        $doneCnt       = 0;
        $inProgressCnt = 0;
        $missedCnt     = 0;
        $availableCnt  = 0;
        $pendingCnt    = 0;
        foreach ($allTasks as $t) {
            $st = $t['status'] ?? '';
            if      ($st === 'done')        $doneCnt++;
            elseif  ($st === 'in_progress') $inProgressCnt++;
            elseif  ($st === 'missed')      $missedCnt++;
            elseif  ($st === 'available')   $availableCnt++;
            elseif  ($st === 'pending')     $pendingCnt++;
        }
        // % как в ThroneBaron: выполнено / всего
        $rate = $totalCnt > 0 ? round($doneCnt / $totalCnt * 100) : 0;

        // Смены — за ту же дату что и задачи
        $shifts     = tb_get('/work-shifts', ['filter[date]' => $date, 'filter[project]' => TB_PROJECT]);
        $shiftsData = $shifts['data'] ?? [];
        $staffTotal = count($shiftsData);
        $came = 0;
        $shiftsFormatted = [];
        foreach ($shiftsData as $s) {
            $hasStarted = !empty($s['started_at']);
            if ($hasStarted) $came++;
            $inTime  = $hasStarted ? date('H:i', strtotime($s['started_at']) + 3*3600) : null;
            $outTime = !empty($s['ended_at']) ? date('H:i', strtotime($s['ended_at']) + 3*3600) : null;
            $profile  = $s['user']['profile'] ?? [];
            $fullName = trim(($profile['last_name'] ?? '') . ' ' . ($profile['first_name'] ?? ''));
            $pos      = strtolower($profile['position'] ?? '');
            if (strpos($pos, 'двор') !== false) $role = 'Дворник';
            elseif (strpos($pos, 'убор') !== false) $role = 'Уборщица';
            elseif (strpos($pos, 'клин') !== false) $role = 'Клинер';
            else $role = $profile['position'] ?? '—';
            $shiftsFormatted[] = [
                'name' => $fullName ?: ($profile['first_name'] ?? '—'),
                'role' => $role,
                'in'   => $inTime,
                'out'  => $outTime,
                'came' => $hasStarted,
            ];
        }

        echo json_encode([
            'date'        => $date,
            'isYesterday' => $isYesterday,
            'tasks'  => [
                'total'       => $totalCnt,
                'done'        => $doneCnt,
                'in_progress' => $inProgressCnt,
                'missed'      => $missedCnt,
                'available'   => $availableCnt,
                'pending'     => $pendingCnt,
                'rate'        => $rate,
            ],
            'staff'  => ['total' => $staffTotal, 'came' => $came, 'absent' => $staffTotal - $came],
            'shifts' => $shiftsFormatted,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Детализация задач по домам (кеш 15 мин)
    //  ?action=house_breakdown&date=2026-05-29
    // ----------------------------------------------------------
    case 'house_breakdown':
        set_time_limit(120);
        $date = $_GET['date'] ?? $today;
        $cacheFile = sys_get_temp_dir() . '/tb_hb_' . TB_PROJECT . '_' . $date . '.json';

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
            header('X-Cache: HIT');
            echo file_get_contents($cacheFile);
            break;
        }

        $allLocs  = get_all_locations();
        $rootLocs = array_filter($allLocs, function($l) {
            return $l['project_id'] == TB_PROJECT && $l['parent_id'] === null;
        });

        $houses = [];
        foreach ($rootLocs as $loc) {
            $locId   = $loc['id'];
            $locName = $loc['name'];
            $parts   = explode(' ', trim($locName), 2);
            $houseId = count($parts) > 1 ? $parts[1] : $locName;

            $done   = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$locId.'*','limit'=>250]);
            $missed = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$locId.'*','limit'=>250]);
            $dc = count($done['data'] ?? []);
            $mc = count($missed['data'] ?? []);
            $total = $dc + $mc;
            $rate  = $total > 0 ? round($dc/$total*100) : 0;
            $status = $rate>=90 ? 'ok' : ($rate>=70 ? 'warn' : 'crit');

            $houses[] = ['id'=>$houseId,'label'=>$locName,'locationId'=>$locId,'done'=>$dc,'missed'=>$mc,'total'=>$total,'pct'=>$rate,'status'=>$status];
        }

        usort($houses, function($a,$b){ return $a['pct'] - $b['pct']; });
        $result = json_encode(['date'=>$date,'houses'=>$houses,'cached'=>false], JSON_UNESCAPED_UNICODE);
        file_put_contents($cacheFile, $result);
        echo $result;
        break;

    // ----------------------------------------------------------
    //  Детализация одного дома: МОП (по секциям) + ПДТ
    //  ?action=house_detail&location_id=1&date=2026-05-29
    //
    //  Логика:
    //    Дом (wildcard) = МОП + ПДТ + прочее
    //    МОП = сумма задач по всем секциям (каждая с wildcard)
    //    ПДТ = Дом − МОП  (то что не попало в секции)
    // ----------------------------------------------------------
    case 'house_detail':
        set_time_limit(120);
        $locId = (int)($_GET['location_id'] ?? 0);
        if (!$locId) { echo json_encode(['error'=>'location_id required']); break; }
        $date = $_GET['date'] ?? $today;

        $allLocs  = get_all_locations();

        // Секции (прямые дети дома)
        $sections = array_values(array_filter($allLocs, function($l) use ($locId) {
            return (int)$l['parent_id'] === $locId;
        }));

        // Всего по дому (wildcard = все вложенные локации)
        $houseD    = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$locId.'*','limit'=>250]);
        $houseM    = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$locId.'*','limit'=>250]);
        $houseDone = count($houseD['data'] ?? []);
        $houseMiss = count($houseM['data'] ?? []);
        $houseTot  = $houseDone + $houseMiss;

        // МОП = задачи по каждой секции (с wildcard = включая этажи)
        $mopZones = []; $mopDone = 0; $mopMiss = 0;
        foreach ($sections as $sec) {
            $sd = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$sec['id'].'*','limit'=>250]);
            $sm = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$sec['id'].'*','limit'=>250]);
            $d  = count($sd['data'] ?? []);
            $m  = count($sm['data'] ?? []);
            $mopDone += $d; $mopMiss += $m;
            $t  = $d + $m;
            $mopZones[] = ['name'=>$sec['name'],'id'=>$sec['id'],'done'=>$d,'missed'=>$m,'total'=>$t,'pct'=> $t>0?round($d/$t*100):0];
        }
        $mopTot = $mopDone + $mopMiss;

        // ПДТ = то что не вошло в секции (придомовая территория)
        $pdtDone = max(0, $houseDone - $mopDone);
        $pdtMiss = max(0, $houseMiss - $mopMiss);
        $pdtTot  = $pdtDone + $pdtMiss;

        echo json_encode([
            'date'   => $date,
            'locId'  => $locId,
            'total'  => ['done'=>$houseDone,'missed'=>$houseMiss,'total'=>$houseTot],
            'mop'    => ['done'=>$mopDone,'missed'=>$mopMiss,'total'=>$mopTot,'pct'=>$mopTot>0?round($mopDone/$mopTot*100):0,'zones'=>$mopZones],
            'pdt'    => ['done'=>$pdtDone,'missed'=>$pdtMiss,'total'=>$pdtTot,'pct'=>$pdtTot>0?round($pdtDone/$pdtTot*100):0],
        ], JSON_UNESCAPED_UNICODE);
        break;


    case 'teams':
        $data = tb_get('/teams', ['project' => TB_PROJECT]);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Диагностика: структура ответа API задач
    //  ?action=task_debug&date=2026-05-29
    // ----------------------------------------------------------
    case 'task_debug':
        $date = $_GET['date'] ?? $today;
        $out  = [];

        // Смотрим структуру первой задачи (без фильтра)
        $r = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'limit'=>3]);
        $out['sample_tasks'] = $r['data'] ?? [];

        // Пробуем разные статусы
        foreach (['done','missed','in_progress'] as $st) {
            $r = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>$st,'limit'=>1]);
            $out['status_'.$st] = [
                'data_count'    => count($r['data'] ?? []),
                'has_next_page' => !empty($r['next_page_url']),
                'sample'        => $r['data'][0] ?? null,
            ];
        }
        // Всего без фильтра (считаем все страницы)
        $all = tb_get_all('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT]);
        $statusCounts = [];
        foreach ($all as $t) {
            $st = $t['status'] ?? $t['state'] ?? '(no_status_field)';
            $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
        }
        $out['total_all_pages']  = count($all);
        $out['status_breakdown'] = $statusCounts;

        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;



    // ----------------------------------------------------------
    //  Локации
    //  ?action=locations
    // ----------------------------------------------------------
    case 'locations':
        $data = tb_get('/locations');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Статистика по домам (командам) за дату
    //  ?action=house_stats&date=2026-05-29
    // ----------------------------------------------------------
    case 'house_stats':
        $tz   = new DateTimeZone('Europe/Moscow');
        $now  = new DateTime('now', $tz);
        $date = $_GET['date'] ?? $now->format('Y-m-d');

        // Получаем все команды проекта
        $teamsData = tb_get('/teams', ['project' => TB_PROJECT]);
        $teams = $teamsData['data'] ?? [];

        // Для каждой команды получаем done/missed задачи по локации
        // Команды named "МОП 28к2" → извлекаем "28к2"
        $houseStats = [];
        foreach ($teams as $team) {
            $tname = $team['name'] ?? '';
            // Извлекаем номер дома из названия команды
            preg_match('/(\d+[\/к]\d+|\d+к\d+|\d+\/\d+)/ui', $tname, $m);
            $houseNum = $m[0] ?? $tname;

            // Задачи команды — фильтр по project, статус done/missed за дату
            // ThroneBaron: assignee t{id} — но в reports нет фильтра по команде
            // Используем location если совпадает, иначе показываем команду как есть
            $houseStats[] = [
                'teamId'   => $team['id'],
                'teamName' => $tname,
                'house'    => $houseNum,
            ];
        }

        echo json_encode([
            'date'   => $date,
            'teams'  => $houseStats,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Помощь: список доступных action
    // ----------------------------------------------------------
    default:
        echo json_encode([
            'endpoints' => [
                '?action=projects'              => 'Список проектов (найти project_id)',
                '?action=dashboard&date=today'  => 'Все KPI за дату — основной запрос дашборда',
                '?action=teams'                 => 'Команды проекта (= дома ЖК)',
                '?action=locations'             => 'Локации проекта',
                '?action=house_stats'           => 'Статистика по командам/домам',
                '?action=shifts&date=2026-05-28'=> 'Смены за дату',
                '?action=tasks&date=today'      => 'Задачи: выполнено / пропущено',
                '?action=users'                 => 'Список активных сотрудников',
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
