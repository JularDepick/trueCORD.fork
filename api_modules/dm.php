<?php
// trueCORD API module (auto-split). Included within the main request scope of truecord_api.php.
// Shares: $db (PDO), $d (request array), $action (string) and all helper functions.
// Served only via include from the router; guard prevents direct/standalone execution.
if (!isset($db) || !isset($d)) { return; }
// --- handlers ---
    if ($action === 'dm_conversations') {
        if (!$meValidated) { apiOk(['conversations'=>[],'restricted'=>true]); }
        $s = $db->prepare("
            SELECT CASE WHEN from_user_id=:uid THEN to_user_id ELSE from_user_id END AS other_id,
                   MAX(id) AS last_id, MAX(at) AS last_at
            FROM dm_messages
            WHERE (from_user_id=:uid OR to_user_id=:uid) AND deleted=0
            GROUP BY other_id ORDER BY last_at DESC
        ");
        $s->execute([':uid' => $meId]);
        $convRows = $s->fetchAll();
        if (empty($convRows)) { apiOk(['conversations' => []]); }

        $otherIds = array_map('intval', array_column($convRows, 'other_id'));
        $lastIds  = array_map('intval', array_column($convRows, 'last_id'));
        $ph       = implode(',', array_fill(0, count($otherIds), '?'));
        $lph      = implode(',', array_fill(0, count($lastIds), '?'));

        $uSt = $db->prepare("SELECT id,name,avatar,status,last_seen FROM users WHERE id IN($ph)");
        $uSt->execute($otherIds); $userMap = [];
        foreach ($uSt->fetchAll() as $u) $userMap[(int)$u['id']] = $u;

        $lmSt = $db->prepare("SELECT id,text,image FROM dm_messages WHERE id IN($lph) AND deleted=0");
        $lmSt->execute($lastIds); $lastMsgMap = [];
        foreach ($lmSt->fetchAll() as $lm) $lastMsgMap[(int)$lm['id']] = $lm;

        $unreadSt = $db->prepare("SELECT from_user_id, COUNT(*) AS cnt FROM dm_messages WHERE from_user_id IN($ph) AND to_user_id=? AND read_at=0 AND deleted=0 GROUP BY from_user_id");
        $unreadSt->execute(array_merge($otherIds, [$meId]));
        $unreadMap = array_column($unreadSt->fetchAll(), 'cnt', 'from_user_id');

        $thr = nowSec() - 90; $convs = [];
        foreach ($convRows as $row) {
            $oid = (int)$row['other_id']; $ou = $userMap[$oid] ?? null; if (!$ou) continue;
            $lm  = $lastMsgMap[(int)$row['last_id']] ?? null;
            $st  = (string)($ou['status'] ?? 'online'); $isOnline = ((int)$ou['last_seen']) >= $thr && $st !== 'invisible';
            $convs[] = ['userId'=>$oid,'name'=>(string)$ou['name'],'avatar'=>(string)($ou['avatar']??''),'status'=>$isOnline?$st:'offline','online'=>$isOnline,'lastMsg'=>$lm?(string)($lm['text']?:($lm['image']?'[файл]':'')):'','unread'=>(int)($unreadMap[$oid]??0)];
        }
        apiOk(['conversations' => $convs]);
    }

    // ══ dm_messages ══════════════════════════════════════════════
    if ($action === 'dm_messages') {
        if (!$meValidated) apiFail('Для использования ЛС необходима верификация аккаунта');
        $oid = (int)($d['withUserId'] ?? 0); if ($oid <= 0) apiFail('Укажите withUserId');
        $since  = (int)($d['since'] ?? 0);
        $before = isset($d['before']) ? (int)$d['before'] : null;
        $limit  = min(50, max(1, (int)($d['limit'] ?? 30)));
        // Колонка edited есть не во всех БД — подставляем её в выборку только если существует.
        $dmHasEdited = false;
        foreach ($db->query("PRAGMA table_info(dm_messages)")->fetchAll() as $c) { if ($c['name'] === 'edited') { $dmHasEdited = true; break; } }
        $edSel = $dmHasEdited ? 'dm.edited' : '0 AS edited';

        if ($before !== null && $before > 0) {
            // Старые сообщения для прокрутки вверх: берём порцию перед первым показанным сообщением.
            $s = $db->prepare("SELECT dm.id,dm.from_user_id,dm.text,dm.image,dm.deleted,$edSel,dm.at,u.name AS sname,u.avatar AS savatar FROM dm_messages dm JOIN users u ON u.id=dm.from_user_id WHERE ((from_user_id=? AND to_user_id=?) OR (from_user_id=? AND to_user_id=?)) AND dm.id<? ORDER BY dm.id DESC LIMIT ?");
            $s->execute([$meId, $oid, $oid, $meId, $before, $limit]);
            $rows = array_reverse($s->fetchAll());
            $hasMore = count($rows) >= $limit;
        } elseif ($since > 0) {
            // Только новые сообщения после последнего показанного.
            $s = $db->prepare("SELECT dm.id,dm.from_user_id,dm.text,dm.image,dm.deleted,$edSel,dm.at,u.name AS sname,u.avatar AS savatar FROM dm_messages dm JOIN users u ON u.id=dm.from_user_id WHERE ((from_user_id=? AND to_user_id=?) OR (from_user_id=? AND to_user_id=?)) AND dm.id>? ORDER BY dm.id ASC LIMIT 50");
            $s->execute([$meId, $oid, $oid, $meId, $since]);
            $rows = $s->fetchAll();
            $hasMore = false;
        } else {
            // Первое открытие ЛС: только последние сообщения, а не вся переписка с начала.
            $s = $db->prepare("SELECT dm.id,dm.from_user_id,dm.text,dm.image,dm.deleted,$edSel,dm.at,u.name AS sname,u.avatar AS savatar FROM dm_messages dm JOIN users u ON u.id=dm.from_user_id WHERE ((from_user_id=? AND to_user_id=?) OR (from_user_id=? AND to_user_id=?)) ORDER BY dm.id DESC LIMIT ?");
            $s->execute([$meId, $oid, $oid, $meId, $limit]);
            $rows = array_reverse($s->fetchAll());
            $totalQ = $db->prepare("SELECT COUNT(*) FROM dm_messages WHERE (from_user_id=? AND to_user_id=?) OR (from_user_id=? AND to_user_id=?)");
            $totalQ->execute([$meId, $oid, $oid, $meId]);
            $hasMore = (int)$totalQ->fetchColumn() > $limit;
        }

        $msgs = [];
        foreach ($rows as $r) {
            $del = (int)($r['deleted'] ?? 0); $raw = $del ? '' : (string)($r['text'] ?? '');
            $msgs[] = ['id'=>(int)$r['id'],'userId'=>(int)$r['from_user_id'],'name'=>(string)$r['sname'],'avatar'=>(string)($r['savatar']??''),'text'=>$raw,'textHtml'=>$del?'<em>[удалено]</em>':linkify($raw),'image'=>$del?'':(string)($r['image']??''),'deleted'=>(bool)$del,'edited'=>(bool)(int)($r['edited'] ?? 0),'at'=>(int)$r['at']];
        }
        // Подтягиваем реакции для показанных сообщений.
        $dmIds = array_map(function($m){ return (int)$m['id']; }, $msgs);
        $dmReacts = getDmReactionsForMsgs($db, $dmIds, $meId);
        foreach ($msgs as &$mm) { $mm['reactions'] = $dmReacts[(int)$mm['id']] ?? []; }
        unset($mm);
        $db->prepare("UPDATE dm_messages SET read_at=? WHERE from_user_id=? AND to_user_id=? AND read_at=0")->execute([nowSec(), $oid, $meId]);
        apiOk(['messages' => $msgs, 'hasMore' => $hasMore]);
    }

    // ══ dm_send ══════════════════════════════════════════════════
    if ($action === 'dm_send') {
        if (!$meValidated) apiFail('Для использования ЛС необходима верификация аккаунта');
        $toId = (int)($d['toUserId'] ?? 0); $text = trim((string)($d['text'] ?? '')); $image = trim((string)($d['image'] ?? ''));
        if (!$text && !$image) apiFail('Пустое сообщение'); if ($toId === $meId) apiFail('Нельзя писать самому себе'); if ($toId <= 0) apiFail('Неверный получатель');
        tc_rateLimit($db, 'dm_send', $meId, 30, 10); // не более 30 ЛС за 10 секунд
        if (mb_strlen($text) > 4000) apiFail('Сообщение слишком длинное');
        $ru = $db->prepare("SELECT id FROM users WHERE id=?"); $ru->execute([$toId]); if (!$ru->fetch()) apiFail('Пользователь не найден');
        if (!canStartDmWith($db, $meId, $toId, $meName, $meGlobalRole)) apiFail('Личные сообщения доступны только пользователям, которые находятся с вами на одном сервере или канале');
        if (!dmPrivacyAllowed($db, $toId, $meId, 'dm', $meName, $meGlobalRole)) apiFail('Пользователь запретил личные сообщения');
        $bl = $db->prepare("SELECT 1 FROM dm_blacklist WHERE user_id=? AND blocked_user_id=?"); $bl->execute([$toId, $meId]); if ($bl->fetch()) apiFail('Пользователь заблокировал вас');
        $at = nowMs();
        $db->prepare("INSERT INTO dm_messages(from_user_id,to_user_id,text,image,deleted,at) VALUES(?,?,?,?,0,?)")->execute([$meId, $toId, $text, $image, $at]);
        $id = (int)$db->lastInsertId();
        apiOk(['msg'=>['id'=>$id,'userId'=>$meId,'name'=>$meName,'avatar'=>(string)($me['avatar']??''),'text'=>$text,'textHtml'=>linkify($text),'image'=>$image,'deleted'=>false,'at'=>$at]]);
    }

    // ══ dm_edit ══════════════════════════════════════════════════
    if ($action === 'dm_edit') {
        if (!$meValidated) apiFail('Для использования ЛС необходима верификация аккаунта');
        $dmId   = (int)($d['messageId'] ?? 0);
        $newText= trim((string)($d['text'] ?? ''));
        if ($dmId <= 0) apiFail('Укажите messageId');
        if ($newText === '') apiFail('Текст не может быть пустым');
        if (mb_strlen($newText) > 4000) apiFail('Сообщение слишком длинное');
        $st = $db->prepare("SELECT from_user_id,deleted FROM dm_messages WHERE id=?");
        $st->execute([$dmId]); $row = $st->fetch();
        if (!$row) apiFail('Сообщение не найдено');
        if ((int)$row['from_user_id'] !== $meId) apiFail('Можно редактировать только свои сообщения');
        if ((int)($row['deleted'] ?? 0)) apiFail('Нельзя редактировать удалённое сообщение');
        // Колонка edited могла отсутствовать в старых БД — добавляем при необходимости.
        $hasEdited = false;
        foreach ($db->query("PRAGMA table_info(dm_messages)")->fetchAll() as $c) { if ($c['name'] === 'edited') { $hasEdited = true; break; } }
        if (!$hasEdited) { try { $db->exec("ALTER TABLE dm_messages ADD COLUMN edited INTEGER DEFAULT 0"); $hasEdited = true; } catch (Exception $e) {} }
        if ($hasEdited) $db->prepare("UPDATE dm_messages SET text=?,edited=1 WHERE id=?")->execute([$newText, $dmId]);
        else            $db->prepare("UPDATE dm_messages SET text=? WHERE id=?")->execute([$newText, $dmId]);
        apiOk(['id'=>$dmId,'text'=>$newText,'textHtml'=>linkify($newText),'edited'=>true]);
    }

    // ══ dm_add_reaction ══════════════════════════════════════════
    if ($action === 'dm_add_reaction') {
        if (!$meValidated) apiFail('Для использования ЛС необходима верификация аккаунта');
        $dmId  = (int)($d['messageId'] ?? 0);
        $emoji = mb_substr(trim((string)($d['emoji'] ?? '')), 0, 8);
        if (!$dmId || $emoji === '') apiFail('Укажите messageId и emoji');
        // Реагировать можно только на сообщение из своей переписки.
        $s = $db->prepare("SELECT id,from_user_id,to_user_id,deleted FROM dm_messages WHERE id=?");
        $s->execute([$dmId]); $dm = $s->fetch();
        if (!$dm) apiFail('Сообщение не найдено');
        $from = (int)$dm['from_user_id']; $to = (int)$dm['to_user_id'];
        if ($meId !== $from && $meId !== $to) apiFail('Нет доступа к сообщению');
        if ((int)($dm['deleted'] ?? 0)) apiFail('Нельзя реагировать на удалённое сообщение');
        $rcol = dmReactionColumn($db) ?: 'dm_message_id';
        $chk = $db->prepare("SELECT id FROM dm_reactions WHERE $rcol=? AND user_id=? AND emoji=?");
        $chk->execute([$dmId, $meId, $emoji]);
        if ($chk->fetch()) {
            $db->prepare("DELETE FROM dm_reactions WHERE $rcol=? AND user_id=? AND emoji=?")->execute([$dmId, $meId, $emoji]);
        } else {
            $cnt = $db->prepare("SELECT COUNT(DISTINCT emoji) FROM dm_reactions WHERE $rcol=?"); $cnt->execute([$dmId]);
            if ((int)$cnt->fetchColumn() >= 20) apiFail('Максимум 20 различных реакций на сообщение');
            $db->prepare("INSERT OR IGNORE INTO dm_reactions($rcol,user_id,emoji,created_at) VALUES(?,?,?,?)")->execute([$dmId, $meId, $emoji, nowSec()]);
        }
        $reacts = getDmReactionsForMsgs($db, [$dmId], $meId);
        apiOk(['reactions'=>$reacts[$dmId]??[],'messageId'=>$dmId]);
    }

    // ══ dm_get_reactions ═════════════════════════════════════════
    if ($action === 'dm_get_reactions') {
        if (!$meValidated) apiFail('Для использования ЛС необходима верификация аккаунта');
        $oid = (int)($d['withUserId'] ?? 0); if ($oid <= 0) apiFail('Укажите withUserId');
        $s = $db->prepare("SELECT id FROM dm_messages WHERE ((from_user_id=? AND to_user_id=?) OR (from_user_id=? AND to_user_id=?)) AND deleted=0");
        $s->execute([$meId, $oid, $oid, $meId]);
        $ids = array_map('intval', array_column($s->fetchAll(), 'id'));
        $out = []; foreach (getDmReactionsForMsgs($db, $ids, $meId) as $mid => $r) $out[(string)$mid] = $r;
        apiOk(['reactions' => $out]);
    }

    // ══ dm_get_reaction_users ════════════════════════════════════
    if ($action === 'dm_get_reaction_users') {
        if (!$meValidated) apiFail('Для использования ЛС необходима верификация аккаунта');
        $dmId  = (int)($d['messageId'] ?? 0);
        $emoji = mb_substr(trim((string)($d['emoji'] ?? '')), 0, 8);
        if (!$dmId || $emoji === '') apiFail('Укажите messageId и emoji');
        $s = $db->prepare("SELECT from_user_id,to_user_id FROM dm_messages WHERE id=?"); $s->execute([$dmId]); $dm = $s->fetch();
        if (!$dm) apiFail('Сообщение не найдено');
        if ($meId !== (int)$dm['from_user_id'] && $meId !== (int)$dm['to_user_id']) apiFail('Нет доступа к сообщению');
        $rcol = dmReactionColumn($db) ?: 'dm_message_id';
        $u = $db->prepare("SELECT us.id,us.name,us.avatar FROM dm_reactions r JOIN users us ON us.id=r.user_id WHERE r.$rcol=? AND r.emoji=? ORDER BY r.created_at ASC");
        $u->execute([$dmId, $emoji]); $users = [];
        foreach ($u->fetchAll() as $row) $users[] = ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'avatar'=>(string)($row['avatar']??'')];
        apiOk(['users'=>$users,'emoji'=>$emoji]);
    }

    // ══ dm_delete ════════════════════════════════════════════════
    if ($action === 'dm_delete') {
        $msgId = (int)($d['messageId'] ?? 0);
        $s     = $db->prepare("SELECT id,from_user_id FROM dm_messages WHERE id=?"); $s->execute([$msgId]); $dm = $s->fetch();
        if (!$dm) apiFail('Сообщение не найдено');
        if ((int)$dm['from_user_id'] !== $meId && $meName !== OWNER_NAME && $meGlobalRole !== 'super_admin') apiFail('Нет прав');
        $db->prepare("UPDATE dm_messages SET deleted=1,text='',image='' WHERE id=?")->execute([$msgId]);
        apiOk(['messageId' => $msgId]);
    }

    // ══ dm_poll ══════════════════════════════════════════════════
    if ($action === 'dm_poll') {
        $oid = (int)($d['withUserId'] ?? 0); $since = (int)($d['since'] ?? 0); if ($oid <= 0) apiFail('Укажите withUserId');
        // dm_poll отдаёт ТОЛЬКО новые сообщения относительно since. Без точки отсчёта (since<=0)
        // это не первичная загрузка (её делает dm_messages), поэтому возвращаем пусто —
        // иначе сервер отдал бы старейшую историю, которая на клиенте уходит в уведомления.
        if ($since <= 0) apiOk(['messages' => []]);
        $s = $db->prepare("SELECT dm.id,dm.from_user_id,dm.text,dm.image,dm.deleted,dm.at,u.name AS sname,u.avatar AS savatar FROM dm_messages dm JOIN users u ON u.id=dm.from_user_id WHERE ((from_user_id=? AND to_user_id=?) OR (from_user_id=? AND to_user_id=?)) AND dm.id>? ORDER BY dm.id ASC LIMIT 50");
        $s->execute([$meId, $oid, $oid, $meId, $since]); $msgs = [];
        foreach ($s->fetchAll() as $r) {
            $del = (int)($r['deleted'] ?? 0); $raw = $del ? '' : (string)($r['text'] ?? '');
            $msgs[] = ['id'=>(int)$r['id'],'userId'=>(int)$r['from_user_id'],'name'=>(string)$r['sname'],'avatar'=>(string)($r['savatar']??''),'text'=>$raw,'textHtml'=>$del?'<em>[удалено]</em>':linkify($raw),'image'=>$del?'':(string)($r['image']??''),'deleted'=>(bool)$del,'at'=>(int)$r['at']];
        }
        if (!empty($msgs)) $db->prepare("UPDATE dm_messages SET read_at=? WHERE from_user_id=? AND to_user_id=? AND read_at=0")->execute([nowSec(), $oid, $meId]);
        apiOk(['messages' => $msgs]);
    }

    // ══ dm_mark_read ═════════════════════════════════════════════
    if ($action === 'dm_mark_read') {
        $fromId = (int)($d['fromUserId'] ?? 0);
        if ($fromId > 0) $db->prepare("UPDATE dm_messages SET read_at=? WHERE from_user_id=? AND to_user_id=? AND read_at=0")->execute([nowSec(), $fromId, $meId]);
        apiOk([]);
    }

    // ══ dm_delete_conversation ═══════════════════════════════════
    if ($action === 'dm_delete_conversation') {
        $oid = (int)($d['withUserId'] ?? 0);
        if ($oid <= 0) apiFail('Укажите withUserId');
        $db->prepare("UPDATE dm_messages SET deleted=1,text='',image='' WHERE (from_user_id=? AND to_user_id=?) OR (from_user_id=? AND to_user_id=?)")
           ->execute([$meId, $oid, $oid, $meId]);
        apiOk(['deleted' => true]);
    }


    // ══ dm_clear_before ══════════════════════════════════════════
    // Удаляет старые сообщения в ЛС для обоих участников до выбранной даты.
    // Это именно физическое удаление строк, чтобы история не превращалась в список "[удалено]".
    if ($action === 'dm_clear_before') {
        if (!$meValidated) apiFail('Для использования ЛС необходима верификация аккаунта');
        $oid = (int)($d['withUserId'] ?? 0);
        $beforeMs = (int)($d['beforeMs'] ?? 0);
        if ($oid <= 0) apiFail('Укажите withUserId');
        if ($oid === $meId) apiFail('Нельзя очищать чат с самим собой');
        if ($beforeMs <= 0) apiFail('Укажите дату очистки');

        // Защита от случайной даты из будущего дальше чем на сутки.
        $maxFuture = nowMs() + 86400000;
        if ($beforeMs > $maxFuture) apiFail('Дата очистки не может быть в будущем');

        $uChk = $db->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
        $uChk->execute([$oid]);
        if (!$uChk->fetch()) apiFail('Пользователь не найден');

        $cnt = $db->prepare("SELECT COUNT(*) FROM dm_messages WHERE (((from_user_id=? AND to_user_id=?) OR (from_user_id=? AND to_user_id=?)) AND at<=?)");
        $cnt->execute([$meId, $oid, $oid, $meId, $beforeMs]);
        $deletedCount = (int)$cnt->fetchColumn();

        $del = $db->prepare("DELETE FROM dm_messages WHERE (((from_user_id=? AND to_user_id=?) OR (from_user_id=? AND to_user_id=?)) AND at<=?)");
        $del->execute([$meId, $oid, $oid, $meId, $beforeMs]);

        apiOk(['deleted' => $deletedCount]);
    }

    // ══ change_password ═════════════════════════════════════════
    if ($action === 'change_password') {
        $oldPass = (string)($d['oldPassword'] ?? '');
        $newPass = (string)($d['newPassword'] ?? '');
        if (mb_strlen($newPass, 'UTF-8') < 4) apiFail('Новый пароль: минимум 4 символа');
        $s = $db->prepare("SELECT pass FROM users WHERE id=?"); $s->execute([$meId]); $row = $s->fetch();
        if (!$row) apiFail('Пользователь не найден');
        if (!password_verify($oldPass, $row['pass'])) apiFail('Неверный текущий пароль');
        $db->prepare("UPDATE users SET pass=? WHERE id=?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $meId]);
        $tok = (string)($d['token'] ?? '');
        if ($tok) $db->prepare("DELETE FROM user_sessions WHERE user_id=? AND token!=?")->execute([$meId, $tok]);
        apiOk([]);
    }


    // ══ DM PRIVACY ═══════════════════════════════════════════════
    if ($action === 'dm_privacy_get') {
        $otherId = (int)($d['otherUserId'] ?? 0);
        $g = $db->prepare("SELECT allow_dm,allow_audio,allow_video FROM dm_privacy WHERE user_id=? AND other_user_id=0 LIMIT 1");
        $g->execute([$meId]);
        $global = $g->fetch() ?: ['allow_dm'=>1,'allow_audio'=>1,'allow_video'=>1];
        $user = null; $other = null;
        if ($otherId > 0) {
            $ou = getUserNameRole($db, $otherId);
            if ($ou) {
                $other = ['id'=>(int)$ou['id'],'name'=>(string)$ou['name'],'isSuperAdmin'=>isSuperAdminIdentity((string)$ou['name'], (string)$ou['global_role'])];
                $u = $db->prepare("SELECT allow_dm,allow_audio,allow_video FROM dm_privacy WHERE user_id=? AND other_user_id=? LIMIT 1");
                $u->execute([$meId, $otherId]);
                $user = $u->fetch() ?: ['allow_dm'=>null,'allow_audio'=>null,'allow_video'=>null];
            }
        }
        apiOk(['global'=>$global,'user'=>$user,'other'=>$other]);
    }

    if ($action === 'dm_privacy_save') {
        $otherId = (int)($d['otherUserId'] ?? 0);
        if ($otherId < 0 || $otherId === $meId) apiFail('Неверный пользователь');
        if ($otherId > 0) {
            $ou = getUserNameRole($db, $otherId);
            if (!$ou) apiFail('Пользователь не найден');
            if (isSuperAdminIdentity((string)$ou['name'], (string)$ou['global_role'])) apiFail('Супер-админа нельзя ограничить');
        }
        $norm = function($v, bool $global): ?int {
            if ($v === null || $v === '' || $v === 'inherit') return $global ? 1 : null;
            return ((string)$v === '0' || (string)$v === 'deny') ? 0 : 1;
        };
        $isGlobal = $otherId === 0;
        $allowDm = $norm($d['allowDm'] ?? null, $isGlobal);
        $allowAudio = $norm($d['allowAudio'] ?? null, $isGlobal);
        $allowVideo = $norm($d['allowVideo'] ?? null, $isGlobal);
        $db->prepare("INSERT OR REPLACE INTO dm_privacy(user_id,other_user_id,allow_dm,allow_audio,allow_video,updated_at) VALUES(?,?,?,?,?,?)")
           ->execute([$meId,$otherId,$allowDm,$allowAudio,$allowVideo,nowSec()]);
        apiOk([]);
    }

    // ══ DM BLACKLIST ═════════════════════════════════════════════
    if ($action === 'dm_blacklist_add')    { $bid=(int)($d['blockUserId']??0); if ($bid<=0||$bid===$meId) apiFail('Неверный ID'); $db->prepare("INSERT OR IGNORE INTO dm_blacklist(user_id,blocked_user_id,created_at) VALUES(?,?,?)")->execute([$meId,$bid,nowSec()]); apiOk([]); }
    if ($action === 'dm_blacklist_remove') { $bid=(int)($d['blockUserId']??0); $db->prepare("DELETE FROM dm_blacklist WHERE user_id=? AND blocked_user_id=?")->execute([$meId,$bid]); apiOk([]); }
    if ($action === 'dm_blacklist_get') {
        $s = $db->prepare("SELECT bl.blocked_user_id,u.name FROM dm_blacklist bl JOIN users u ON u.id=bl.blocked_user_id WHERE bl.user_id=?"); $s->execute([$meId]); $list = [];
        foreach ($s->fetchAll() as $r) $list[] = ['id'=>(int)$r['blocked_user_id'],'name'=>$r['name']];
        apiOk(['list' => $list]);
    }

    // ══ DM CALL SYSTEM ═══════════════════════════════════════════
    // ══ GAME INVITE SIGNALS ══════════════════════════════════════
    if ($action === 'game_invite') {
        $toId = (int)($d['toUserId'] ?? 0);
        $game = (string)($d['game'] ?? '');
        $data = (string)($d['data'] ?? '');
        if (!$toId || !$game) apiFail('Неверные параметры');
        $now = nowSec();
        // Удаляем предыдущее непринятое приглашение от этого пользователя
        $db->prepare("DELETE FROM dm_call_signals WHERE from_user_id=? AND to_user_id=? AND type='game_invite' AND created_at>?")
           ->execute([$meId, $toId, $now - 120]);
        $db->prepare("INSERT INTO dm_call_signals(from_user_id,to_user_id,type,data,created_at) VALUES(?,?,?,?,?)")
           ->execute([$meId, $toId, 'game_invite', json_encode(['game'=>$game,'data'=>json_decode($data,true),'fromName'=>$meName,'fromAvatar'=>(string)($me['avatar']??'')]), $now]);
        apiOk(['signalId' => (int)$db->lastInsertId()]);
    }

    if ($action === 'game_accept') {
        $toId = (int)($d['toUserId'] ?? 0);
        $game = (string)($d['game'] ?? '');
        $data = (string)($d['data'] ?? '');
        if (!$toId) apiFail('Неверные параметры');
        $db->prepare("INSERT INTO dm_call_signals(from_user_id,to_user_id,type,data,created_at) VALUES(?,?,?,?,?)")
           ->execute([$meId, $toId, 'game_accept', json_encode(['game'=>$game,'data'=>json_decode($data,true),'fromName'=>$meName]), nowSec()]);
        apiOk([]);
    }

    if ($action === 'game_reject') {
        $toId = (int)($d['toUserId'] ?? 0);
        $game = (string)($d['game'] ?? '');
        if (!$toId) apiFail('Неверные параметры');
        $db->prepare("INSERT INTO dm_call_signals(from_user_id,to_user_id,type,data,created_at) VALUES(?,?,?,?,?)")
           ->execute([$meId, $toId, 'game_reject', json_encode(['game'=>$game,'fromName'=>$meName]), nowSec()]);
        apiOk([]);
    }

    if ($action === 'game_cancel') {
        $toId = (int)($d['toUserId'] ?? 0);
        $game = (string)($d['game'] ?? '');
        if (!$toId) apiFail('Неверные параметры');
        $db->prepare("INSERT INTO dm_call_signals(from_user_id,to_user_id,type,data,created_at) VALUES(?,?,?,?,?)")
           ->execute([$meId, $toId, 'game_cancel', json_encode(['game'=>$game,'fromName'=>$meName]), nowSec()]);
        apiOk([]);
    }

    if ($action === 'game_signal') {
        $toId = (int)($d['toUserId'] ?? 0);
        $type = (string)($d['type'] ?? '');
        $data = (string)($d['data'] ?? '');
        if (!$toId || !$type) apiFail('Неверные параметры');
        if (!in_array($type, ['game_move','game_chat','game_over'], true)) apiFail('Неверный тип');
        $now = nowSec();
        $db->prepare("INSERT INTO dm_call_signals(from_user_id,to_user_id,type,data,created_at) VALUES(?,?,?,?,?)")
           ->execute([$meId, $toId, $type, $data, $now]);
        if (rand(1,5)===1) $db->prepare("DELETE FROM dm_call_signals WHERE type IN ('game_move','game_chat') AND created_at<?")->execute([$now-3600]);
        apiOk([]);
    }

    // ══ DM CALL ══════════════════════════════════════════════════
    if ($action === 'dm_call_start') {
        if (!$meValidated) apiFail('Для голосовых звонков необходима верификация аккаунта');
        $toId = (int)($d['toUserId'] ?? 0); if ($toId <= 0 || $toId === $meId) apiFail('Неверный ID');
        $ru = $db->prepare("SELECT id FROM users WHERE id=?"); $ru->execute([$toId]); if (!$ru->fetch()) apiFail('Пользователь не найден');
        $isVideoCall = !empty($d['video']);
        if (!dmPrivacyAllowed($db, $toId, $meId, $isVideoCall ? 'video' : 'audio', $meName, $meGlobalRole)) apiFail($isVideoCall ? 'Пользователь запретил видеозвонки' : 'Пользователь запретил аудиозвонки');
        $bl = $db->prepare("SELECT 1 FROM dm_blacklist WHERE user_id=? AND blocked_user_id=?"); $bl->execute([$toId, $meId]); if ($bl->fetch()) apiFail('Пользователь заблокировал вас');
        $callData = $isVideoCall ? json_encode(['video'=>1], JSON_UNESCAPED_UNICODE) : '';
        $db->prepare("INSERT INTO dm_call_signals(from_user_id,to_user_id,type,data,created_at) VALUES(?,?,'incoming_call',?,?)")->execute([$meId, $toId, $callData, nowSec()]);
        apiOk(['signalId' => (int)$db->lastInsertId()]);
    }
    if ($action === 'dm_call_answer') { $toId=(int)($d['toUserId']??0); if ($toId<=0) apiFail('Неверный ID'); $db->prepare("INSERT INTO dm_call_signals(from_user_id,to_user_id,type,data,created_at) VALUES(?,?,'call_answered','',?)")->execute([$meId,$toId,nowSec()]); apiOk([]); }
    if ($action === 'dm_call_reject') { $toId=(int)($d['toUserId']??0); if ($toId<=0) apiFail('Неверный ID'); $db->prepare("INSERT INTO dm_call_signals(from_user_id,to_user_id,type,data,created_at) VALUES(?,?,'call_rejected','',?)")->execute([$meId,$toId,nowSec()]); apiOk([]); }
    if ($action === 'dm_call_hangup') { $toId=(int)($d['toUserId']??0); if ($toId<=0) apiFail('Неверный ID'); $db->prepare("INSERT INTO dm_call_signals(from_user_id,to_user_id,type,data,created_at) VALUES(?,?,'call_hangup','',?)")->execute([$meId,$toId,nowSec()]); apiOk([]); }

    if ($action === 'dm_call_signal') {
        $toId = (int)($d['toUserId']??0); $type = (string)($d['type']??''); $data = (string)($d['data']??'');
        if (!$toId||!$type||!$data) apiFail('Неверные параметры');
        if (!in_array($type, ['offer','answer','ice-candidate','ice-restart','renegotiate','video-state'], true)) apiFail('Неверный тип сигнала');
        $now = nowSec();
        $db->prepare("INSERT INTO dm_call_signals(from_user_id,to_user_id,type,data,created_at) VALUES(?,?,?,?,?)")->execute([$meId,$toId,$type,$data,$now]);
        $db->prepare("DELETE FROM dm_call_signals WHERE created_at<?")->execute([$now-600]);
        apiOk([]);
    }

    // ══ VOICE ════════════════════════════════════════════════════
