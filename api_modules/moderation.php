<?php
// trueCORD API module (auto-split). Included within the main request scope of truecord_api.php.
// Shares: $db (PDO), $d (request array), $action (string) and all helper functions.
// Served only via include from the router; guard prevents direct/standalone execution.
if (!isset($db) || !isset($d)) { return; }
// --- handlers ---
    if ($action === 'set_typing') {
        $chId = (int)($d['channelId'] ?? 0); if (!$chId) apiFail('Укажите channelId');
        $now  = nowSec();
        $db->prepare("INSERT OR REPLACE INTO typing_signals(channel_id,user_id,user_name,updated_at) VALUES(?,?,?,?)")->execute([$chId, $meId, $meName, $now]);
        $db->prepare("DELETE FROM typing_signals WHERE updated_at<?")->execute([$now - 10]);
        apiOk([]);
    }

    // ══ get_typing ═══════════════════════════════════════════════
    if ($action === 'get_typing') {
        $chId = (int)($d['channelId'] ?? 0); if (!$chId) apiFail('Укажите channelId');
        $s    = $db->prepare("SELECT user_name FROM typing_signals WHERE channel_id=? AND user_id!=? AND updated_at>=?");
        $s->execute([$chId, $meId, nowSec() - 8]);
        apiOk(['typing' => array_column($s->fetchAll(), 'user_name')]);
    }

    // ══ mute_user ════════════════════════════════════════════════
    if ($action === 'mute_user') {
        $sid = (int)($d['serverId'] ?? 0); $targetId = (int)($d['targetId'] ?? 0); $duration = (int)($d['duration'] ?? 0); $reason = trim((string)($d['reason'] ?? ''));
        $myRole = getRole($db, $sid, $meId);
        if (!isMod($myRole) && $meGlobalRole !== 'super_admin') apiFail('Нет прав');
        $targetRole = getRole($db, $sid, $targetId);
        if ($myRole === 'moderator' && isAdmin($targetRole)) apiFail('Модератор не может заглушить администратора');
        if ($targetId === $meId) apiFail('Нельзя заглушить себя');
        $muteUntil = $duration > 0 ? nowSec() + $duration * 60 : 0;
        $db->prepare("INSERT INTO user_mutes(server_id,user_id,muted_by,reason,muted_until,created_at) VALUES(?,?,?,?,?,?)")->execute([$sid, $targetId, $meId, $reason, $muteUntil, nowSec()]);
        tc_logModeration($db, $me, 'mute_user', $targetId, '', $sid, $reason);
        apiOk(['mutedUntil' => $muteUntil]);
    }

    // ══ unmute_user ══════════════════════════════════════════════
    if ($action === 'unmute_user') {
        $sid = (int)($d['serverId'] ?? 0); $targetId = (int)($d['targetId'] ?? 0);
        $myRole = getRole($db, $sid, $meId);
        if (!isMod($myRole) && $meGlobalRole !== 'super_admin') apiFail('Нет прав');
        $db->prepare("DELETE FROM user_mutes WHERE server_id=? AND user_id=?")->execute([$sid, $targetId]);
        apiOk([]);
    }

    // ══ kick_user ════════════════════════════════════════════════
    if ($action === 'kick_user') {
        $sid = (int)($d['serverId'] ?? 0); $targetId = (int)($d['targetId'] ?? 0); $reason = trim((string)($d['reason'] ?? ''));
        $myRole = getRole($db, $sid, $meId);
        if (!isMod($myRole) && $meGlobalRole !== 'super_admin') apiFail('Нет прав');
        $targetRole = getRole($db, $sid, $targetId);
        if ($myRole === 'moderator' && isAdmin($targetRole)) apiFail('Модератор не может выгнать администратора');
        if ($targetId === $meId) apiFail('Нельзя выгнать себя');
        $db->prepare("UPDATE server_members SET is_member=0,left_at=? WHERE server_id=? AND user_id=?")->execute([nowSec(), $sid, $targetId]);
        $db->prepare("INSERT INTO server_kicks(server_id,user_id,kicked_by,reason,created_at) VALUES(?,?,?,?,?)")->execute([$sid, $targetId, $meId, $reason, nowSec()]);
        tc_logModeration($db, $me, 'kick_user', $targetId, '', $sid, $reason);
        apiOk([]);
    }

    // ══ validate_user ════════════════════════════════════════════
    if ($action === 'validate_user') {
        $targetId = (int)($d['targetId'] ?? 0); if ($targetId <= 0) apiFail('Укажите targetId');
        $canValidate = $meName === OWNER_NAME || $meGlobalRole === 'super_admin' || $meGlobalRole === 'project_admin';
        if (!$canValidate) {
            $checkSid = (int)($d['serverId'] ?? 0);
            if ($checkSid > 0) {
                $srvRole = getRole($db, $checkSid, $meId);
                $canValidate = isMod($srvRole);
            } else {
                $s = $db->prepare("SELECT role FROM server_members WHERE user_id=? AND role IN('owner','admin','moderator') AND is_member=1 LIMIT 1");
                $s->execute([$meId]);
                if ($s->fetch()) $canValidate = true;
            }
        }
        if (!$canValidate) apiFail('Нет прав для верификации');
        $db->prepare("UPDATE users SET validated=1,validated_by=? WHERE id=?")->execute([$meId, $targetId]);
        apiOk(['validated'=>true,'targetId'=>$targetId]);
    }

    // ══ unvalidate_user ══════════════════════════════════════════
    if ($action === 'unvalidate_user') {
        $targetId = (int)($d['targetId'] ?? 0);
        if ($meName !== OWNER_NAME && $meGlobalRole !== 'super_admin') {
            $s = $db->prepare("SELECT role FROM server_members WHERE user_id=? AND role IN('owner','admin') LIMIT 1"); $s->execute([$meId]); if (!$s->fetch()) apiFail('Нет прав');
        }
        $tgtRole = $db->prepare("SELECT role FROM server_members WHERE user_id=? AND role IN('owner','admin','moderator') LIMIT 1"); $tgtRole->execute([$targetId]);
        if ($tgtRole->fetch()) apiFail('Нельзя снять верификацию с администратора/модератора/владельца');
        $db->prepare("UPDATE users SET validated=0,validated_by=0 WHERE id=?")->execute([$targetId]);
        apiOk([]);
    }

    // ══ global_ban_user ══════════════════════════════════════════
    if ($action === 'global_ban_user') {
        if ($meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin' && $meName !== OWNER_NAME) apiFail('Недостаточно прав');
        $targetId = (int)($d['targetId'] ?? 0); $reason = trim((string)($d['reason'] ?? ''));
        if ($targetId <= 0) apiFail('Укажите targetId'); if ($targetId === $meId) apiFail('Нельзя заблокировать себя');
        $uq = $db->prepare("SELECT id,name,reg_ip FROM users WHERE id=?"); $uq->execute([$targetId]); $u = $uq->fetch();
        if (!$u) apiFail('Пользователь не найден');
        $db->prepare("INSERT OR REPLACE INTO global_bans(user_id,username,reg_ip,banned_by,reason,created_at) VALUES(?,?,?,?,?,?)")->execute([$targetId, (string)$u['name'], (string)($u['reg_ip']??''), $meId, $reason, nowSec()]);
        $db->prepare("DELETE FROM user_sessions WHERE user_id=?")->execute([$targetId]);
        tc_logModeration($db, $me, 'global_ban_user', $targetId, (string)$u['name'], 0, $reason);
        apiOk(['banned'=>true,'username'=>(string)$u['name']]);
    }

    // ══ global_unban_user ════════════════════════════════════════
    if ($action === 'global_unban_user') {
        if ($meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin' && $meName !== OWNER_NAME) apiFail('Недостаточно прав');
        $targetId = (int)($d['targetId'] ?? 0);
        $db->prepare("DELETE FROM global_bans WHERE user_id=?")->execute([$targetId]);
        tc_logModeration($db, $me, 'global_unban_user', $targetId, '', 0, '');
        apiOk([]);
    }

    // ══ get_global_bans ══════════════════════════════════════════
    if ($action === 'get_global_bans') {
        if ($meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin' && $meName !== OWNER_NAME) apiFail('Недостаточно прав');
        $s = $db->prepare("SELECT gb.*,u2.name AS banned_by_name FROM global_bans gb LEFT JOIN users u2 ON u2.id=gb.banned_by ORDER BY gb.created_at DESC LIMIT 100");
        $s->execute(); $bans = [];
        foreach ($s->fetchAll() as $b) $bans[] = ['id'=>(int)$b['id'],'userId'=>(int)$b['user_id'],'username'=>(string)$b['username'],'reason'=>(string)$b['reason'],'bannedBy'=>(string)($b['banned_by_name']??''),'createdAt'=>(int)$b['created_at']];
        apiOk(['bans' => $bans]);
    }

    // ══ set_global_role ══════════════════════════════════════════
    if ($action === 'set_global_role') {
        if ($meName !== OWNER_NAME && $meGlobalRole !== 'super_admin') apiFail('Только ' . OWNER_NAME . ' или супер-администратор может назначать глобальные роли');
        $targetId = (int)($d['targetId'] ?? 0); $role = (string)($d['role'] ?? '');
        if (!in_array($role, ['super_admin','project_admin',''], true)) apiFail('Неверная роль');
        if ($role === 'super_admin' && $meName !== OWNER_NAME) apiFail('Только ' . OWNER_NAME . ' может назначать супер-администраторов');
        $db->prepare("UPDATE users SET global_role=? WHERE id=?")->execute([$role, $targetId]);
        if ($role !== '') $db->prepare("UPDATE users SET validated=1 WHERE id=?")->execute([$targetId]);
        tc_logModeration($db, $me, 'set_global_role', $targetId, '', 0, $role !== '' ? ('role=' . $role) : 'role removed');
        apiOk(['role' => $role]);
    }

    // ══ get_moderation_log ═══════════════════════════════════════
    // Просмотр аудит-лога действий модерации. Доступен супер-админам и админам проекта.
    if ($action === 'get_moderation_log') {
        if ($meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin' && $meName !== OWNER_NAME) apiFail('Недостаточно прав');
        $limit = min(500, max(1, (int)($d['limit'] ?? 100)));
        $s = $db->prepare("SELECT * FROM moderation_log ORDER BY created_at DESC, id DESC LIMIT ?");
        $s->execute([$limit]);
        $rows = [];
        foreach ($s->fetchAll() as $r) {
            $rows[] = [
                'id'         => (int)$r['id'],
                'actorId'    => (int)$r['actor_id'],
                'actorName'  => (string)$r['actor_name'],
                'action'     => (string)$r['action'],
                'targetId'   => (int)$r['target_id'],
                'targetName' => (string)$r['target_name'],
                'serverId'   => (int)$r['server_id'],
                'reason'     => (string)$r['reason'],
                'createdAt'  => (int)$r['created_at'],
            ];
        }
        apiOk(['log' => $rows]);
    }

    // ══ INVITE SYSTEM ════════════════════════════════════════════
    if ($action === 'create_invite') {
        $sid = (int)($d['serverId'] ?? 0); $myRole = getRole($db, $sid, $meId);
        if (!isAdmin($myRole) && $meName !== OWNER_NAME) apiFail('Нет прав для создания приглашений');
        $maxUses = (int)($d['maxUses'] ?? 0); $expHours = (int)($d['expiresHours'] ?? 0);
        $code = bin2hex(random_bytes(5)); $expiresAt = $expHours > 0 ? nowSec() + $expHours * 3600 : 0;
        $db->prepare("INSERT INTO server_invites(server_id,creator_id,code,max_uses,uses,expires_at,created_at) VALUES(?,?,?,?,0,?,?)")->execute([$sid, $meId, $code, $maxUses, $expiresAt, nowSec()]);
        apiOk(['code'=>$code,'link'=>inviteLink($code)]);
    }

    if ($action === 'get_invites') {
        $sid = (int)($d['serverId'] ?? 0); $myRole = getRole($db, $sid, $meId);
        if (!isAdmin($myRole) && $meName !== OWNER_NAME && $meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin') apiFail('Нет прав');
        $rows = $db->prepare("SELECT i.*,u.name AS creator_name FROM server_invites i JOIN users u ON u.id=i.creator_id WHERE i.server_id=? ORDER BY i.created_at DESC");
        $rows->execute([$sid]); $invites = [];
        foreach ($rows->fetchAll() as $r) $invites[] = ['code'=>(string)$r['code'],'link'=>inviteLink((string)$r['code']),'maxUses'=>(int)$r['max_uses'],'uses'=>(int)$r['uses'],'expiresAt'=>(int)$r['expires_at'],'createdAt'=>(int)$r['created_at'],'creatorName'=>(string)$r['creator_name']];
        apiOk(['invites' => $invites]);
    }


    if ($action === 'join_by_invite') {
        $code = normalizeInviteCode($d['code'] ?? '');
        if (!$code) apiFail('Неверный код');
        $s = $db->prepare("SELECT i.*,s.name AS server_name FROM server_invites i JOIN servers s ON s.id=i.server_id WHERE i.code=?");
        $s->execute([$code]); $inv = $s->fetch();
        if (!$inv) apiFail('Приглашение не найдено');
        if ($inv['expires_at'] > 0 && (int)$inv['expires_at'] < nowSec()) apiFail('Срок действия истёк');
        if ($inv['max_uses'] > 0 && (int)$inv['uses'] >= (int)$inv['max_uses']) apiFail('Лимит использований исчерпан');
        $sid = (int)$inv['server_id']; ensureMember($db, $sid, $meId);
        $db->prepare("UPDATE server_invites SET uses=uses+1 WHERE code=?")->execute([$code]);
        apiOk(['serverId'=>$sid,'serverName'=>(string)$inv['server_name']]);
    }

    if ($action === 'delete_invite') {
        $sid = (int)($d['serverId'] ?? 0); $code = trim((string)($d['code'] ?? ''));
        $myRole = getRole($db, $sid, $meId);
        if (!isAdmin($myRole) && $meName !== OWNER_NAME && $meGlobalRole !== 'super_admin' && $meGlobalRole !== 'project_admin') apiFail('Нет прав');
        $db->prepare("DELETE FROM server_invites WHERE code=? AND server_id=?")->execute([$code, $sid]);
        apiOk([]);
    }

    // ══ DM CONVERSATIONS ═════════════════════════════════════════
