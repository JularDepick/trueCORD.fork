<?php
// trueCORD API module (auto-split). Included within the main request scope of truecord_api.php.
// Shares: $db (PDO), $d (request array), $action (string) and all helper functions.
// Served only via include from the router; guard prevents direct/standalone execution.
if (!isset($db) || !isset($d)) { return; }
// --- handlers ---
    if ($action === 'get_servers') {
        $rows = $db->query("SELECT id,name,description,icon,owner_id FROM servers ORDER BY id")->fetchAll();
        if (empty($rows)) { apiOk(['servers' => []]); }

        $sids = array_map('intval', array_column($rows, 'id'));
        $ph   = implode(',', array_fill(0, count($sids), '?'));
        $now  = nowSec(); $thr = $now - 15;

        $memSt = $db->prepare("SELECT server_id,is_member,role FROM server_members WHERE server_id IN($ph) AND user_id=?");
        $memSt->execute(array_merge($sids, [$meId]));
        $memMap = [];
        foreach ($memSt->fetchAll() as $r) $memMap[(int)$r['server_id']] = $r;

        $kickSt = $db->prepare("SELECT server_id FROM server_kicks WHERE server_id IN($ph) AND user_id=? GROUP BY server_id");
        $kickSt->execute(array_merge($sids, [$meId]));
        $kickMap = array_flip(array_column($kickSt->fetchAll(), 'server_id'));

        $cntSt = $db->prepare("SELECT server_id, COUNT(*) AS cnt FROM server_members WHERE server_id IN($ph) AND is_member=1 GROUP BY server_id");
        $cntSt->execute($sids);
        $cntMap = array_column($cntSt->fetchAll(), 'cnt', 'server_id');

        $vcntSt = $db->prepare("SELECT vr.server_id, COUNT(*) AS cnt FROM voice_participants vp JOIN voice_rooms vr ON vr.id=vp.room_id WHERE vr.server_id IN($ph) AND vp.last_ping>=? GROUP BY vr.server_id");
        $vcntSt->execute(array_merge($sids, [$thr]));
        $vcntMap = array_column($vcntSt->fetchAll(), 'cnt', 'server_id');

        $nsSt = $db->prepare("SELECT server_id,muted FROM server_notif_settings WHERE user_id=? AND server_id IN($ph)");
        $nsSt->execute(array_merge([$meId], $sids));
        $nsMap = array_column($nsSt->fetchAll(), 'muted', 'server_id');

        $list = [];
        $globalCanSeeAll = hasGlobalManagePower($meName, $meGlobalRole);
        foreach ($rows as $srv) {
            $sid      = (int)$srv['id'];
            $memRow   = $memMap[$sid] ?? null;
            $isMember = $memRow && (int)$memRow['is_member'] === 1;
            if ((int)$srv['owner_id'] === $meId) {
                $isMember = true;
                if (!$memRow) ensureMember($db, $sid, $meId);
            }
            if ($globalCanSeeAll) {
                // Супер-админ видит и открывает все серверы, но обычное членство не выдаётся автоматически.
                $isMember = true;
            }
            $role = 'none';
            if ($isMember) $role = $memRow['role'] ?? ($globalCanSeeAll ? 'admin' : 'member');
            if ((int)$srv['owner_id'] === $meId) $role = 'owner';
            if ($globalCanSeeAll && $role === 'member') $role = 'admin';
            // Если MAIN_SERVER_ALWAYS_VISIBLE=true (tes3chat), основной сервер №1 не скрывается даже в invite_only.
            $mainAlwaysVisible = (defined('MAIN_SERVER_ALWAYS_VISIBLE') && MAIN_SERVER_ALWAYS_VISIBLE && $sid === 1);
            if (!$mainAlwaysVisible && defined('SERVER_DIRECTORY_MODE') && SERVER_DIRECTORY_MODE === 'invite_only' && !$isMember && (int)$srv['owner_id'] !== $meId && !$globalCanSeeAll) continue;
            $list[] = [
                'id'          => $sid,
                'name'        => (string)$srv['name'],
                'description' => (string)($srv['description'] ?? ''),
                'icon'        => (string)($srv['icon'] ?? ''),
                'ownerId'     => (int)$srv['owner_id'],
                'role'        => $role,
                'memberCount' => (int)($cntMap[$sid] ?? 0),
                'voiceActive' => ((int)($vcntMap[$sid] ?? 0)) > 0,
                'kicked'      => isset($kickMap[$sid]),
                'isMember'    => $isMember,
                'notifMuted'  => isset($nsMap[$sid]) ? (bool)(int)$nsMap[$sid] : false,
            ];
        }
        apiOk(['servers' => $list]);
    }

    // ══ join_server ══════════════════════════════════════════════
    if ($action === 'join_server') {
        $sid = (int)($d['serverId'] ?? 0);
        if ($sid <= 0) apiFail('Укажите serverId');
        $srv = $db->prepare("SELECT id,name,owner_id FROM servers WHERE id=?"); $srv->execute([$sid]); $srvRow = $srv->fetch();
        if (!$srvRow) apiFail('Сервер не найден');
        $mainAlwaysVisibleJoin = (defined('MAIN_SERVER_ALWAYS_VISIBLE') && MAIN_SERVER_ALWAYS_VISIBLE && $sid === 1);
        if (!$mainAlwaysVisibleJoin && defined('SERVER_DIRECTORY_MODE') && SERVER_DIRECTORY_MODE === 'invite_only' && (int)$srvRow['owner_id'] !== $meId && !hasGlobalManagePower($meName, $meGlobalRole)) {
            $m = $db->prepare("SELECT is_member FROM server_members WHERE server_id=? AND user_id=?");
            $m->execute([$sid, $meId]);
            $mr = $m->fetch();
            if (!$mr || (int)$mr['is_member'] !== 1) apiFail('Вступление доступно только по приглашению');
        }
        ensureMember($db, $sid, $meId);
        apiOk(['serverId' => $sid, 'serverName' => (string)$srvRow['name']]);
    }

    // ══ leave_server ═════════════════════════════════════════════
    if ($action === 'leave_server') {
        $sid  = (int)($d['serverId'] ?? 0);
        if ($sid <= 0) apiFail('Укажите serverId');
        $role = getRole($db, $sid, $meId);
        if ($role === 'owner') apiFail('Владелец не может покинуть сервер. Сначала передайте права.');
        $db->prepare("UPDATE server_members SET is_member=0,left_at=? WHERE server_id=? AND user_id=?")->execute([nowSec(), $sid, $meId]);
        $db->prepare("DELETE FROM voice_participants WHERE user_id=? AND room_id IN (SELECT id FROM voice_rooms WHERE server_id=?)")->execute([$meId, $sid]);
        apiOk(['left' => true, 'serverId' => $sid]);
    }

    // ══ set_server_notif ═════════════════════════════════════════
    if ($action === 'set_server_notif') {
        $sid   = (int)($d['serverId'] ?? 0);
        $muted = (int)($d['muted'] ?? 0);
        if ($sid <= 0) apiFail('Укажите serverId');
        $db->prepare("INSERT OR REPLACE INTO server_notif_settings(user_id,server_id,muted) VALUES(?,?,?)")->execute([$meId, $sid, $muted]);
        apiOk(['muted' => (bool)$muted]);
    }

    // ══ delete_user ══════════════════════════════════════════════
    if ($action === 'delete_user') {
        if ($meName !== OWNER_NAME && $meGlobalRole !== 'super_admin') apiFail('Недостаточно прав');
        $targetId = (int)($d['targetId'] ?? 0);
        if ($targetId <= 0) apiFail('Укажите targetId');
        if ($targetId === $meId) apiFail('Нельзя удалить себя');
        $uq = $db->prepare("SELECT name,global_role FROM users WHERE id=?"); $uq->execute([$targetId]); $u = $uq->fetch();
        if (!$u) apiFail('Пользователь не найден');
        if ($u['global_role'] === 'super_admin' && $meName !== OWNER_NAME) apiFail('Нельзя удалить другого супер-администратора');
        $db->exec('BEGIN');
        try {
            foreach (['user_sessions','server_members','voice_participants','typing_signals','user_server_roles','server_notif_settings'] as $tbl)
                $db->prepare("DELETE FROM $tbl WHERE user_id=?")->execute([$targetId]);
            $db->prepare("DELETE FROM user_mutes WHERE user_id=?")->execute([$targetId]);
            $db->prepare("UPDATE messages SET deleted=1,text='[аккаунт удалён]',image='' WHERE user_id=?")->execute([$targetId]);
            $db->prepare("UPDATE dm_messages SET deleted=1,text='',image='' WHERE from_user_id=?")->execute([$targetId]);
            $db->prepare("DELETE FROM dm_blacklist WHERE user_id=? OR blocked_user_id=?")->execute([$targetId, $targetId]);
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$targetId]);
            $db->exec('COMMIT');
        } catch (Exception $e) {
            try { $db->exec('ROLLBACK'); } catch (Exception $e2) {}
            apiFail('Ошибка удаления: ' . $e->getMessage());
        }
        apiOk(['deleted' => true, 'targetId' => $targetId, 'username' => (string)$u['name']]);
    }

    // ══ create_server ════════════════════════════════════════════
    if ($action === 'create_server') {
        // Создание сервера зависит от config.json -> permissions.create_server.
        // При включённой политике верификации обычный пользователь должен быть подтверждён.
        $isValidated = (int)($me['validated'] ?? 0) === 1;
        if (!$isValidated && REQUIRE_VALIDATION && !hasGlobalManagePower($meName, $meGlobalRole)) {
            apiFail('Создание серверов доступно только после верификации аккаунта');
        }
        tc_rateLimit($db, 'create_server', $meId, 5, 300); // не более 5 серверов за 5 минут
        $canCreate = permissionAllows(CREATE_SERVER_PERMISSION, $myRole ?? 'member', $meName, $meGlobalRole, $isValidated);
        if (!$canCreate) apiFail('Нет прав для создания серверов');
        $name = trim((string)($d['name'] ?? ''));
        $desc = trim((string)($d['description'] ?? ''));
        $icon = trim((string)($d['icon'] ?? ''));
        // При создании сервера поле icon предназначено только для короткой эмодзи/символа.
        // PNG/загрузка иконки идут отдельным update_server_icon, поэтому длинный мусор/data-url очищаем.
        if (mb_strlen($icon) > 8 || strpbrk($icon, " \t\r\n=\"'<>") !== false) $icon = '';
        if (!$name) apiFail('Введите название');
        $t = nowSec();
        $db->exec('BEGIN');
        try {
            $db->prepare("INSERT INTO servers(name,description,icon,owner_id,created_at) VALUES(?,?,?,?,?)")->execute([$name, $desc, $icon, $meId, $t]);
            $sid = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO channels(server_id,owner_id,name,topic,description,avatar,created_at) VALUES(?,?,?,?,?,?,?)")->execute([$sid, $meId, DEFAULT_CHANNEL_NAME, DEFAULT_CHANNEL_TOPIC, DEFAULT_SERVER_DESCRIPTION, defined('DEFAULT_CHANNEL_ICON') ? DEFAULT_CHANNEL_ICON : '', $t]);
            $db->prepare("INSERT OR REPLACE INTO server_members(server_id,user_id,role,joined_at,is_member) VALUES(?,?,'owner',?,1)")->execute([$sid, $meId, $t]);
            $db->prepare("INSERT INTO voice_rooms(server_id,name,position,created_at) VALUES(?,?,0,?)")->execute([$sid, 'Main', $t]);
            $db->prepare("INSERT INTO voice_rooms(server_id,name,position,created_at) VALUES(?,?,1,?)")->execute([$sid, 'Second', $t]);
            // ★ FIX v16: все пользователи добавляются как НЕ участники (is_member=0)
            // Они сами вступят по желанию через join_server
            foreach ($db->query("SELECT id FROM users")->fetchAll() as $u)
                $db->prepare("INSERT OR IGNORE INTO server_members(server_id,user_id,role,joined_at,is_member) VALUES(?,?,'member',?,0)")->execute([$sid, (int)$u['id'], $t]);
            // Создатель сервера — участник
            $db->prepare("UPDATE server_members SET is_member=1 WHERE server_id=? AND user_id=?")->execute([$sid, $meId]);
            $db->exec('COMMIT');
        } catch (Exception $e) {
            try { $db->exec('ROLLBACK'); } catch (Exception $e2) {}
            apiFail('Ошибка создания сервера: ' . $e->getMessage());
        }
        apiOk(['server' => ['id'=>$sid,'name'=>$name,'description'=>$desc,'icon'=>$icon,'role'=>'owner','isMember'=>true]]);
    }

    // ══ delete_server ════════════════════════════════════════════
    if ($action === 'delete_server') {
        $sid    = (int)($d['serverId'] ?? 0);
        if ($sid <= 0) apiFail('Укажите serverId');
        $myRole = getRole($db, $sid, $meId);
        if ($meName !== OWNER_NAME && $meGlobalRole !== 'super_admin' && $myRole !== 'owner') apiFail('Нет прав для удаления сервера');
        $db->exec('BEGIN');
        try {
            $channels = $db->prepare("SELECT id FROM channels WHERE server_id=?"); $channels->execute([$sid]);
            foreach ($channels->fetchAll() as $ch) {
                $chId   = (int)$ch['id'];
                $msgIds = $db->prepare("SELECT id FROM messages WHERE channel_id=?"); $msgIds->execute([$chId]);
                $mids   = array_map('intval', array_column($msgIds->fetchAll(), 'id'));
                if (!empty($mids)) {
                    $mph = implode(',', array_fill(0, count($mids), '?'));
                    $db->prepare("DELETE FROM reactions WHERE message_id IN($mph)")->execute($mids);
                    $db->prepare("DELETE FROM message_comments WHERE message_id IN($mph)")->execute($mids);
                }
                $db->prepare("DELETE FROM messages WHERE channel_id=?")->execute([$chId]);
                $db->prepare("DELETE FROM typing_signals WHERE channel_id=?")->execute([$chId]);
            }
            $db->prepare("DELETE FROM channels WHERE server_id=?")->execute([$sid]);
            $vrooms = $db->prepare("SELECT id FROM voice_rooms WHERE server_id=?"); $vrooms->execute([$sid]);
            foreach ($vrooms->fetchAll() as $r) {
                $rid = (int)$r['id'];
                $db->prepare("DELETE FROM voice_participants WHERE room_id=?")->execute([$rid]);
                $db->prepare("DELETE FROM voice_signals WHERE room_id=?")->execute([$rid]);
                $db->prepare("DELETE FROM voice_events WHERE room_id=?")->execute([$rid]);
            }
            foreach (['voice_rooms','server_members','server_invites','server_kicks','user_mutes','server_roles','user_server_roles','server_notif_settings'] as $tbl)
                $db->prepare("DELETE FROM $tbl WHERE server_id=?")->execute([$sid]);
            $db->prepare("DELETE FROM servers WHERE id=?")->execute([$sid]);
            $db->exec('COMMIT');
        } catch (Exception $e) {
            try { $db->exec('ROLLBACK'); } catch (Exception $e2) {}
            apiFail('Ошибка удаления: ' . $e->getMessage());
        }
        apiOk(['serverId' => $sid]);
    }

    // ══ update_server ════════════════════════════════════════════
    if ($action === 'update_server') {
        $sid  = (int)($d['serverId'] ?? 0); $role = getRole($db, $sid, $meId);
        if (!isAdmin($role)) apiFail('Нет прав');
        $name = trim((string)($d['name'] ?? '')); $desc = trim((string)($d['description'] ?? '')); $icon = trim((string)($d['icon'] ?? ''));
        $isImgIcon = (bool)preg_match('/^(https?:|data:image\/|uploads\/|\.\/uploads\/)/i', $icon) || (bool)preg_match('/\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i', $icon);
        if (!$isImgIcon && (mb_strlen($icon) > 8 || strpbrk($icon, " \t\r\n=\"'<>/") !== false)) $icon = '';
        if (!$name) apiFail('Название обязательно');
        $db->prepare("UPDATE servers SET name=?,description=?,icon=? WHERE id=?")->execute([$name, $desc, $icon, $sid]);
        apiOk([]);
    }

    // ══ update_server_icon ═══════════════════════════════════════
    if ($action === 'update_server_icon') {
        $sid = (int)($d['serverId'] ?? 0); $myRole = getRole($db, $sid, $meId);
        if (!isAdmin($myRole) && $meName !== OWNER_NAME && $meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin') apiFail('Нет прав');
        $url = doUpload($db, $meId, (string)($d['image'] ?? ''), (string)($d['mime'] ?? 'image/jpeg'));
        $db->prepare("UPDATE servers SET icon=? WHERE id=?")->execute([$url, $sid]);
        apiOk(['icon' => $url, 'serverId' => $sid]);
    }

    // ══ transfer_server_ownership ════════════════════════════════
    if ($action === 'transfer_server_ownership') {
        $sid        = (int)($d['serverId'] ?? 0);
        $newOwnerId = (int)($d['newOwnerId'] ?? 0);
        $myRole     = getRole($db, $sid, $meId);
        if ($myRole !== 'owner' && $meName !== OWNER_NAME) apiFail('Только владелец может передать права сервера');
        if ($newOwnerId <= 0) apiFail('Укажите нового владельца');
        if ($newOwnerId === $meId) apiFail('Нельзя передать права самому себе');
        $uq = $db->prepare("SELECT id,name FROM users WHERE id=?"); $uq->execute([$newOwnerId]); $nou = $uq->fetch();
        if (!$nou) apiFail('Пользователь не найден');
        $db->exec('BEGIN');
        try {
            $db->prepare("UPDATE servers SET owner_id=? WHERE id=?")->execute([$newOwnerId, $sid]);
            $db->prepare("UPDATE server_members SET role='member' WHERE server_id=? AND user_id=?")->execute([$sid, $meId]);
            $db->prepare("INSERT OR REPLACE INTO server_members(server_id,user_id,role,joined_at,is_member) VALUES(?,?,'owner',?,1)")->execute([$sid, $newOwnerId, nowSec()]);
            $db->prepare("UPDATE users SET validated=1 WHERE id=?")->execute([$newOwnerId]);
            $db->exec('COMMIT');
        } catch (Exception $e) {
            try { $db->exec('ROLLBACK'); } catch (Exception $e2) {}
            apiFail('Ошибка: ' . $e->getMessage());
        }
        apiOk(['serverId'=>$sid,'newOwnerId'=>$newOwnerId,'newOwnerName'=>(string)$nou['name']]);
    }

    // ══ get_server_members ═══════════════════════════════════════
    if ($action === 'get_server_members') {
        $sid = (int)($d['serverId'] ?? 0);
        $s   = $db->prepare("
            SELECT u.id, u.name, u.avatar, u.status, u.last_seen, u.global_role,
                   COALESCE(sm.role,'member') AS role,
                   CASE WHEN u.validated=1 OR u.global_role!=''
                        OR COALESCE(sm.role,'member') IN('owner','admin','moderator')
                        THEN 1 ELSE 0 END AS eff_validated
            FROM users u
            LEFT JOIN server_members sm ON sm.user_id=u.id AND sm.server_id=?
            WHERE sm.is_member=1
            ORDER BY CASE COALESCE(sm.role,'member')
                WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'moderator' THEN 2 ELSE 3
            END, u.name
        ");
        $s->execute([$sid]);
        $rows = $s->fetchAll();
        if (empty($rows)) { apiOk(['members' => []]); }

        $userIds = array_map('intval', array_column($rows, 'id'));
        $ph      = implode(',', array_fill(0, count($userIds), '?'));
        $now     = nowSec();

        $mutesSt = $db->prepare("
            SELECT user_id, MAX(muted_until) AS muted_until
            FROM user_mutes
            WHERE server_id=? AND user_id IN($ph)
              AND (muted_until=0 OR muted_until>?)
            GROUP BY user_id
        ");
        $mutesSt->execute(array_merge([$sid], $userIds, [$now]));
        $mutesMap = array_column($mutesSt->fetchAll(), 'muted_until', 'user_id');

        $colorsSt = $db->prepare("
            SELECT usr.user_id, sr.color
            FROM user_server_roles usr
            JOIN server_roles sr ON sr.id = usr.role_id
            WHERE usr.server_id=? AND usr.user_id IN($ph)
            ORDER BY sr.position DESC
        ");
        $colorsSt->execute(array_merge([$sid], $userIds));
        $colorsMap = [];
        foreach ($colorsSt->fetchAll() as $cr) {
            $cuid = (int)$cr['user_id'];
            if (!isset($colorsMap[$cuid])) $colorsMap[$cuid] = (string)$cr['color'];
        }

        $thr     = $now - 90;
        $members = [];
        foreach ($rows as $u) {
            $uid      = (int)$u['id'];
            $st       = (string)($u['status'] ?? 'online');
            $isOnline = ((int)$u['last_seen']) >= $thr && $st !== 'invisible';
            $members[] = [
                'id'         => $uid,
                'name'       => (string)$u['name'],
                'avatar'     => (string)($u['avatar'] ?? ''),
                'role'       => (string)$u['role'],
                'status'     => $isOnline ? $st : 'offline',
                'online'     => $isOnline,
                'validated'  => (int)$u['eff_validated'],
                'globalRole' => (string)($u['global_role'] ?? ''),
                'mutedUntil' => (int)($mutesMap[$uid] ?? 0),
                'roleColor'  => $colorsMap[$uid] ?? '',
            ];
        }
        apiOk(['members' => $members]);
    }

    // ══ set_member_role ══════════════════════════════════════════
    if ($action === 'set_member_role') {
        $sid = (int)($d['serverId'] ?? 0); $targetId = (int)($d['targetId'] ?? 0); $newRole = (string)($d['role'] ?? 'member');
        $myRole = getRole($db, $sid, $meId);
        if (!isAdmin($myRole) && $meGlobalRole !== 'super_admin') apiFail('Нет прав');
        if ($newRole === 'admin' && $myRole !== 'owner' && $meGlobalRole !== 'super_admin') apiFail('Только владелец назначает администраторов');
        if (!in_array($newRole, ['admin','moderator','member'], true)) apiFail('Неверная роль');
        $db->prepare("INSERT OR REPLACE INTO server_members(server_id,user_id,role,joined_at,is_member) VALUES(?,?,?,?,1)")->execute([$sid, $targetId, $newRole, nowSec()]);
        if (in_array($newRole, ['admin','moderator'], true)) $db->prepare("UPDATE users SET validated=1 WHERE id=?")->execute([$targetId]);
        apiOk(['role' => $newRole]);
    }

    // ══ ROLES SYSTEM ═════════════════════════════════════════════
    if ($action === 'get_roles') {
        $sid = (int)($d['serverId'] ?? 0);
        $s   = $db->prepare("SELECT id,name,color,position,permissions FROM server_roles WHERE server_id=? ORDER BY position,id");
        $s->execute([$sid]); $roles = [];
        foreach ($s->fetchAll() as $r) $roles[] = ['id'=>(int)$r['id'],'name'=>(string)$r['name'],'color'=>(string)$r['color'],'position'=>(int)$r['position'],'permissions'=>(string)$r['permissions']];
        apiOk(['roles' => $roles]);
    }

    if ($action === 'create_role') {
        $sid = (int)($d['serverId'] ?? 0); $myRole = getRole($db, $sid, $meId);
        if (!isAdmin($myRole) && $meName !== OWNER_NAME && $meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin') apiFail('Нет прав');
        $name = trim((string)($d['name'] ?? '')); $color = (string)($d['color'] ?? '#c9aa71');
        if (!$name) apiFail('Введите название роли');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#c9aa71';
        $posQ = $db->prepare("SELECT COALESCE(MAX(position),0)+1 FROM server_roles WHERE server_id=?"); $posQ->execute([$sid]);
        $nextPos = (int)$posQ->fetchColumn();
        $db->prepare("INSERT INTO server_roles(server_id,name,color,position,created_at) VALUES(?,?,?,?,?)")->execute([$sid, $name, $color, $nextPos, nowSec()]);
        $id = (int)$db->lastInsertId();
        apiOk(['role' => ['id'=>$id,'name'=>$name,'color'=>$color,'position'=>$nextPos]]);
    }

    if ($action === 'update_role') {
        $roleId = (int)($d['roleId'] ?? 0); $rq = $db->prepare("SELECT server_id FROM server_roles WHERE id=?"); $rq->execute([$roleId]); $rr = $rq->fetch();
        if (!$rr) apiFail('Роль не найдена');
        $myRole = getRole($db, (int)$rr['server_id'], $meId);
        if (!isAdmin($myRole) && $meName !== OWNER_NAME && $meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin') apiFail('Нет прав');
        $name = trim((string)($d['name'] ?? '')); $color = (string)($d['color'] ?? '#c9aa71');
        if (!$name) apiFail('Введите название');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#c9aa71';
        $db->prepare("UPDATE server_roles SET name=?,color=? WHERE id=?")->execute([$name, $color, $roleId]);
        apiOk(['roleId'=>$roleId,'name'=>$name,'color'=>$color]);
    }

    if ($action === 'delete_role') {
        $roleId = (int)($d['roleId'] ?? 0); $rq = $db->prepare("SELECT server_id FROM server_roles WHERE id=?"); $rq->execute([$roleId]); $rr = $rq->fetch();
        if (!$rr) apiFail('Роль не найдена');
        $myRole = getRole($db, (int)$rr['server_id'], $meId);
        if (!isAdmin($myRole) && $meName !== OWNER_NAME && $meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin') apiFail('Нет прав');
        $db->prepare("DELETE FROM user_server_roles WHERE role_id=?")->execute([$roleId]);
        $db->prepare("DELETE FROM server_roles WHERE id=?")->execute([$roleId]);
        apiOk([]);
    }

    if ($action === 'assign_role') {
        $sid = (int)($d['serverId'] ?? 0); $targetId = (int)($d['targetId'] ?? 0); $roleId = (int)($d['roleId'] ?? 0);
        $myRole = getRole($db, $sid, $meId);
        if (!isAdmin($myRole) && $meName !== OWNER_NAME && $meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin') apiFail('Нет прав');
        $rq = $db->prepare("SELECT id FROM server_roles WHERE id=? AND server_id=?"); $rq->execute([$roleId, $sid]);
        if (!$rq->fetch()) apiFail('Роль не найдена');
        $db->prepare("INSERT OR REPLACE INTO user_server_roles(server_id,user_id,role_id) VALUES(?,?,?)")->execute([$sid, $targetId, $roleId]);
        apiOk([]);
    }

    if ($action === 'remove_role') {
        $sid = (int)($d['serverId'] ?? 0); $targetId = (int)($d['targetId'] ?? 0); $roleId = (int)($d['roleId'] ?? 0);
        $myRole = getRole($db, $sid, $meId);
        if (!isAdmin($myRole) && $meName !== OWNER_NAME && $meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin') apiFail('Нет прав');
        $db->prepare("DELETE FROM user_server_roles WHERE server_id=? AND user_id=? AND role_id=?")->execute([$sid, $targetId, $roleId]);
        apiOk([]);
    }

    if ($action === 'get_user_roles') {
        $sid = (int)($d['serverId'] ?? 0); $targetId = (int)($d['targetId'] ?? $meId);
        $s   = $db->prepare("SELECT sr.id,sr.name,sr.color FROM user_server_roles usr JOIN server_roles sr ON sr.id=usr.role_id WHERE usr.server_id=? AND usr.user_id=? ORDER BY sr.position");
        $s->execute([$sid, $targetId]); $roles = [];
        foreach ($s->fetchAll() as $r) $roles[] = ['id'=>(int)$r['id'],'name'=>(string)$r['name'],'color'=>(string)$r['color']];
        apiOk(['roles' => $roles]);
    }

    // ══ get_role_members — batch: все user_id с данной ролью ═════
    if ($action === 'get_role_members') {
        $sid = (int)($d['serverId'] ?? 0); $roleId = (int)($d['roleId'] ?? 0);
        if (!$roleId) apiFail('Укажите roleId');
        $s = $db->prepare("SELECT usr.user_id FROM user_server_roles usr WHERE usr.server_id=? AND usr.role_id=?");
        $s->execute([$sid, $roleId]);
        $userIds = array_map('intval', array_column($s->fetchAll(), 'user_id'));
        apiOk(['userIds' => $userIds]);
    }

    // ══ MESSAGE COMMENTS ═════════════════════════════════════════
    if ($action === 'get_comments') {
        $msgId = (int)($d['messageId'] ?? 0); if (!$msgId) apiFail('Укажите messageId');
        $s = $db->prepare("SELECT id,user_id,user_name,user_avatar,text,at FROM message_comments WHERE message_id=? ORDER BY at ASC");
        $s->execute([$msgId]); $comments = [];
        foreach ($s->fetchAll() as $r) $comments[] = ['id'=>(int)$r['id'],'userId'=>(int)$r['user_id'],'name'=>(string)$r['user_name'],'avatar'=>(string)($r['user_avatar']??''),'text'=>(string)$r['text'],'at'=>(int)$r['at']];
        apiOk(['comments' => $comments]);
    }

    if ($action === 'add_comment') {
        $msgId = (int)($d['messageId'] ?? 0); $text = trim((string)($d['text'] ?? ''));
        if (!$msgId) apiFail('Укажите messageId'); if (!$text) apiFail('Пустой комментарий');
        if (mb_strlen($text) > 500) apiFail('Комментарий слишком длинный (макс. 500)');
        $mq = $db->prepare("SELECT id,channel_id,deleted FROM messages WHERE id=?"); $mq->execute([$msgId]); $msg = $mq->fetch();
        if (!$msg) apiFail('Сообщение не найдено');
        if ((int)($msg['deleted'] ?? 0)) apiFail('Нельзя комментировать удалённое сообщение');
        $at = nowMs();
        $db->prepare("INSERT INTO message_comments(message_id,user_id,user_name,user_avatar,text,at) VALUES(?,?,?,?,?,?)")->execute([$msgId, $meId, $meName, (string)($me['avatar']??''), $text, $at]);
        $id = (int)$db->lastInsertId();
        $cc = $db->prepare("SELECT COUNT(*) FROM message_comments WHERE message_id=?"); $cc->execute([$msgId]);
        apiOk(['comment'=>['id'=>$id,'userId'=>$meId,'name'=>$meName,'avatar'=>(string)($me['avatar']??''),'text'=>$text,'at'=>$at],'commentCount'=>(int)$cc->fetchColumn()]);
    }

    if ($action === 'delete_comment') {
        $cmtId = (int)($d['commentId'] ?? 0);
        $s     = $db->prepare("SELECT id,user_id FROM message_comments WHERE id=?"); $s->execute([$cmtId]); $c = $s->fetch();
        if (!$c) apiFail('Комментарий не найден');
        if ((int)$c['user_id'] !== $meId && $meName !== OWNER_NAME && $meGlobalRole !== 'super_admin') apiFail('Нет прав');
        $db->prepare("DELETE FROM message_comments WHERE id=?")->execute([$cmtId]);
        apiOk([]);
    }

    // ══ get_channels ═════════════════════════════════════════════
