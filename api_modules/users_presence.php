<?php
// trueCORD API module (auto-split). Included within the main request scope of truecord_api.php.
// Shares: $db (PDO), $d (request array), $action (string) and all helper functions.
// Served only via include from the router; guard prevents direct/standalone execution.
if (!isset($db) || !isset($d)) { return; }
// --- handlers ---
    if ($action === 'heartbeat') {
        $now       = nowSec();
        $newStatus = (string)($d['status'] ?? '');
        if (in_array($newStatus, ['online','away','dnd','invisible'], true)) {
            $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$newStatus, $meId]);
        }
        $thr = $now - 90;

        $s = $db->prepare("
            SELECT u.id, u.name, u.avatar, u.status, u.global_role,
                   CASE WHEN u.validated=1 OR u.global_role!=''
                        OR EXISTS(SELECT 1 FROM server_members sm2
                                  WHERE sm2.user_id=u.id AND sm2.role IN('owner','admin','moderator'))
                        THEN 1 ELSE 0 END AS eff_validated
            FROM users u
            WHERE u.last_seen >= ? AND (u.status != 'invisible' OR u.id = ?)
            ORDER BY u.name
        ");
        $s->execute([$thr, $meId]);
        $online = [];
        foreach ($s->fetchAll() as $u) {
            $online[] = ['id'=>(int)$u['id'],'name'=>$u['name'],'avatar'=>(string)($u['avatar']??''),'status'=>(string)($u['status']??'online'),'validated'=>(int)$u['eff_validated'],'globalRole'=>(string)($u['global_role']??'')];
        }

        $s2 = $db->prepare("SELECT COUNT(*) FROM dm_messages WHERE to_user_id=? AND read_at=0 AND deleted=0");
        $s2->execute([$meId]);

        $callSince = (int)($d['callSinceId'] ?? 0);
        $sc        = $db->prepare("
            SELECT dcs.id, dcs.from_user_id, dcs.type, dcs.data,
                   u.name AS from_name, u.avatar AS from_avatar
            FROM dm_call_signals dcs
            JOIN users u ON u.id = dcs.from_user_id
            WHERE dcs.to_user_id=? AND dcs.id>?
            ORDER BY dcs.id ASC LIMIT 20
        ");
        $sc->execute([$meId, $callSince]);
        $callSignals = []; $lastCallId = $callSince;
        foreach ($sc->fetchAll() as $r) {
            $callSignals[] = ['id'=>(int)$r['id'],'fromId'=>(int)$r['from_user_id'],'fromName'=>(string)$r['from_name'],'fromAvatar'=>(string)($r['from_avatar']??''),'type'=>(string)$r['type'],'data'=>(string)$r['data']];
            $lastCallId = max($lastCallId, (int)$r['id']);
        }

        $vesSince    = (int)($d['voiceEventsSince'] ?? 0);
        $ve          = $db->prepare("SELECT id,room_id,user_id,user_name,event_type FROM voice_events WHERE id>? AND created_at>=? ORDER BY id ASC LIMIT 50");
        $ve->execute([$vesSince, $now - 60]);
        $voiceEvents = []; $lastVeId = $vesSince;
        foreach ($ve->fetchAll() as $r) {
            $voiceEvents[] = ['id'=>(int)$r['id'],'roomId'=>(int)$r['room_id'],'userId'=>(int)$r['user_id'],'userName'=>(string)$r['user_name'],'type'=>(string)$r['event_type']];
            $lastVeId = max($lastVeId, (int)$r['id']);
        }

        if (random_int(1, 20) === 1) {
            try {
                $db->exec('BEGIN');
                $db->prepare("DELETE FROM voice_events WHERE created_at < ?")->execute([$now - 120]);
                $db->prepare("DELETE FROM dm_call_signals WHERE created_at < ? AND type NOT IN ('game_invite','game_accept','game_reject','game_cancel')")->execute([$now - 600]);
                $db->prepare("DELETE FROM dm_call_signals WHERE type IN ('game_invite','game_accept','game_reject','game_cancel') AND created_at < ?")->execute([$now - 300]);
                $db->prepare("DELETE FROM dm_call_signals WHERE type IN ('game_move','game_chat','game_over') AND created_at < ?")->execute([$now - 7200]);
                $db->prepare("DELETE FROM voice_signals WHERE created_at < ?")->execute([$now - 180]);
                $db->exec('COMMIT');
            } catch (Exception $e) {
                try { $db->exec('ROLLBACK'); } catch (Exception $e2) {}
            }
        }

        // ── Game invite signals ──────────────────────────────────
        $gameSince = (int)($d['gameSince'] ?? 0);
        $sg = $db->prepare("
            SELECT dcs.id, dcs.from_user_id, dcs.type, dcs.data,
                   u.name AS from_name, u.avatar AS from_avatar
            FROM dm_call_signals dcs
            JOIN users u ON u.id = dcs.from_user_id
            WHERE dcs.to_user_id=? AND dcs.id>?
              AND dcs.type IN ('game_invite','game_accept','game_reject','game_cancel','game_move','game_chat','game_over')
            ORDER BY dcs.id ASC LIMIT 30
        ");
        $sg->execute([$meId, $gameSince]);
        $gameSignals = []; $lastGameId = $gameSince;
        foreach ($sg->fetchAll() as $r) {
            $gameSignals[] = [
                'id'         => (int)$r['id'],
                'fromId'     => (int)$r['from_user_id'],
                'fromName'   => (string)$r['from_name'],
                'fromAvatar' => (string)($r['from_avatar'] ?? ''),
                'type'       => (string)$r['type'],
                'data'       => (string)$r['data'],
            ];
            $lastGameId = max($lastGameId, (int)$r['id']);
        }

        apiOk(['online'=>$online,'dmUnread'=>(int)$s2->fetchColumn(),'callSignals'=>$callSignals,'lastCallId'=>$lastCallId,'voiceEvents'=>$voiceEvents,'lastVoiceEventId'=>$lastVeId,'gameSignals'=>$gameSignals,'lastGameId'=>$lastGameId]);
    }

    // ══ set_status ═══════════════════════════════════════════════
    if ($action === 'set_status') {
        $st = (string)($d['status'] ?? 'online');
        if (!in_array($st, ['online','away','dnd','invisible'], true)) apiFail('Неверный статус');
        $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$st, $meId]);
        apiOk(['status' => $st]);
    }

    // ══ get_users ════════════════════════════════════════════════
    if ($action === 'get_users') {
        $thr = nowSec() - 90;
        $s   = $db->query("
            SELECT u.id, u.name, u.avatar, u.status, u.last_seen, u.global_role,
                   CASE WHEN u.validated=1 OR u.global_role!=''
                        OR EXISTS(SELECT 1 FROM server_members sm2
                                  WHERE sm2.user_id=u.id AND sm2.role IN('owner','admin','moderator'))
                        THEN 1 ELSE 0 END AS eff_validated
            FROM users u ORDER BY u.name
        ");
        $users = [];
        foreach ($s->fetchAll() as $u) {
            $uid      = (int)$u['id'];
            $st       = (string)($u['status'] ?? 'online');
            $isOnline = ((int)$u['last_seen']) >= $thr && $st !== 'invisible';
            $sharedDm = $uid !== $meId && usersShareDmSpace($db, $meId, $uid);
            $dmPolicy = defined('DM_POLICY') ? DM_POLICY : 'shared_space';
            if ($uid === $meId) {
                $canDm = false;
            } elseif (isSuperAdminIdentity($meName, $meGlobalRole)) {
                $canDm = true;
            } elseif ($dmPolicy === 'everyone') {
                $canDm = true;
            } elseif ($dmPolicy === 'verified_only') {
                // Отправитель (текущий пользователь) должен быть верифицирован.
                $canDm = $meValidated || $meGlobalRole !== '';
            } else { // shared_space
                $canDm = $sharedDm;
            }
            $users[]  = [
                'id'=>$uid,
                'name'=>$u['name'],
                'avatar'=>(string)($u['avatar']??''),
                'status'=>$isOnline?$st:'offline',
                'online'=>$isOnline,
                'validated'=>(int)$u['eff_validated'],
                'globalRole'=>(string)($u['global_role']??''),
                'sharedDm'=>$sharedDm,
                'canDm'=>$canDm
            ];
        }
        apiOk(['users' => $users]);
    }

    // ══ get_servers ══════════════════════════════════════════════
