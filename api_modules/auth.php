<?php
// trueCORD API module (auto-split). Included within the main request scope of truecord_api.php.
// Shares: $db (PDO), $d (request array), $action (string) and all helper functions.
// Served only via include from the router; guard prevents direct/standalone execution.
if (!isset($db) || !isset($d)) { return; }
// --- handlers ---
    // ══ register ═════════════════════════════════════════════════
    if ($action === 'register') {
        if (!REGISTRATION_OPEN) apiFail('Регистрация закрыта');
        $name   = trim((string)($d['name'] ?? ''));
        $pass   = (string)($d['pass'] ?? '');
        $accept = (bool)($d['termsAccepted'] ?? false);
        if (REQUIRE_TERMS_ACCEPTANCE && !$accept) apiFail('Необходимо принять Пользовательское соглашение');

        // Первый запуск: разрешаем создать владельца проекта без проверки минимальной длины имени.
        // После появления первого пользователя все остальные аккаунты проходят обычные требования.
        $isFirstAccount = ((int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0);
        $isFirstProjectAdmin = $isFirstAccount && FIRST_REGISTERED_USER_BECOMES_SUPER_ADMIN;
        $canBypassNameMin = $isFirstProjectAdmin && FIRST_ADMIN_BYPASS_USERNAME_MIN_LENGTH;
        if (!$canBypassNameMin && mb_strlen($name, 'UTF-8') < USERNAME_MIN_LEN) apiFail('Имя: минимум ' . USERNAME_MIN_LEN . ' символа(ов)');
        if (mb_strlen($name, 'UTF-8') > USERNAME_MAX_LEN) apiFail('Имя: максимум ' . USERNAME_MAX_LEN . ' символов');
        if (mb_strlen($pass, 'UTF-8') < PASSWORD_MIN_LEN) apiFail('Пароль: минимум ' . PASSWORD_MIN_LEN . ' символов');
        if (PASSWORD_MAX_LEN > 0 && mb_strlen($pass, 'UTF-8') > PASSWORD_MAX_LEN) apiFail('Пароль: максимум ' . PASSWORD_MAX_LEN . ' символов');
        if (!preg_match('/^[\w\-\.\x{0400}-\x{04FF}\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}]+$/u', $name)) apiFail('Недопустимые символы в имени');
        $ip    = getClientIP();
        $ipBan = $db->prepare("SELECT id FROM global_bans WHERE reg_ip=? AND reg_ip!='' LIMIT 1");
        $ipBan->execute([$ip]);
        if ($ipBan->fetch()) apiFail('Регистрация с вашего IP заблокирована');
        $s = $db->prepare("SELECT id FROM users WHERE name=?");
        $s->execute([$name]);
        if ($s->fetch()) apiFail('Имя уже занято');
        $hash  = password_hash($pass, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(32));
        $t     = nowSec();
        $validated = REQUIRE_VALIDATION ? (AUTO_VALIDATE ? 1 : 0) : 1;
        $db->prepare("INSERT INTO users(name,pass,token,avatar,status,last_seen,created_at,terms_accepted,validated,reg_ip) VALUES(?,?,?,'','online',?,?,?,?,?)")
           ->execute([$name, $hash, $token, $t, $t, REQUIRE_TERMS_ACCEPTANCE ? 1 : 0, $validated, $ip]);
        $id = (int)$db->lastInsertId();
        $db->prepare("INSERT OR IGNORE INTO user_sessions(user_id,token,created_at,last_seen) VALUES(?,?,?,?)")
           ->execute([$id, $token, $t, $t]);
        // Новые пользователи автоматически вступают только в основной сервер trueCORD.
        // Остальные серверы остаются invite-only и открываются только по приглашению.
        $globalRole = '';
        if (($isFirstAccount && FIRST_REGISTERED_USER_BECOMES_SUPER_ADMIN) || $name === OWNER_NAME) {
            $globalRole = 'super_admin';
            $db->prepare("UPDATE users SET global_role='super_admin', validated=1 WHERE id=?")->execute([$id]);
            $db->prepare("UPDATE servers SET owner_id=? WHERE id=1 AND (owner_id IS NULL OR owner_id=0)")->execute([$id]);
            $db->prepare("INSERT OR REPLACE INTO server_members(server_id,user_id,role,joined_at,is_member) VALUES(1,?,'owner',?,1)")->execute([$id, $t]);
            $validated = 1;
        } else {
            // Членство нового пользователя в основном сервере зависит от политики сборки:
            //  trueCORD (NEW_USER_JOINS_MAIN=true)  -> сразу участник (is_member=1)
            //  tes3chat (NEW_USER_JOINS_MAIN=false) -> НЕ участник (is_member=0), вступает сам через join_server
            $initialMember = NEW_USER_JOINS_MAIN ? 1 : 0;
            $db->prepare("INSERT OR REPLACE INTO server_members(server_id,user_id,role,joined_at,is_member) VALUES(1,?,'member',?,?)")->execute([$id, $t, $initialMember]);
        }
        apiOk(['user' => ['id'=>$id,'name'=>$name,'avatar'=>'','status'=>'online','validated'=>$validated,'globalRole'=>$globalRole], 'token' => $token]);
    }

    // ══ login ════════════════════════════════════════════════════
    if ($action === 'login') {
        $name = trim((string)($d['name'] ?? ''));
        $pass = (string)($d['pass'] ?? '');
        $ip   = getClientIP();
        // Антибрутфорс: если IP временно заблокирован — отказываем до проверки пароля.
        $lockLeft = loginLockRemaining($db, $ip);
        if ($lockLeft > 0) {
            $mins = (int)ceil($lockLeft / 60);
            apiFail('Слишком много попыток входа. Повторите через ' . $mins . ' мин.');
        }
        $s    = $db->prepare("SELECT id,name,pass,avatar,status,global_role,validated FROM users WHERE name=?");
        $s->execute([$name]); $u = $s->fetch();
        if (!$u || !password_verify($pass, $u['pass'])) { loginRegisterFail($db, $ip); apiFail('Неверное имя или пароль'); }
        if (isGloballyBanned($db, (int)$u['id'], $ip))  apiFail('Ваш аккаунт заблокирован');
        // Успешная аутентификация — сбрасываем счётчик попыток.
        loginResetFails($db, $ip);

        $validatedVal = (int)($u['validated'] ?? 0);
        if (!REQUIRE_VALIDATION && !$validatedVal) {
            $validatedVal = 1;
            $db->prepare("UPDATE users SET validated=1 WHERE id=?")->execute([(int)$u['id']]);
        }
        if (!$validatedVal) {
            if (!empty($u['global_role'])) {
                $validatedVal = 1;
            } else {
                $chkR = $db->prepare("SELECT 1 FROM server_members WHERE user_id=? AND role IN('owner','admin','moderator') LIMIT 1");
                $chkR->execute([(int)$u['id']]);
                if ($chkR->fetch()) $validatedVal = 1;
            }
            if ($validatedVal) $db->prepare("UPDATE users SET validated=1 WHERE id=?")->execute([(int)$u['id']]);
        }

        $token = bin2hex(random_bytes(32));
        $t     = nowSec();
        $db->exec('BEGIN');
        try {
            $db->prepare("UPDATE users SET last_seen=?,status='online' WHERE id=?")->execute([$t, (int)$u['id']]);
            $db->prepare("INSERT INTO user_sessions(user_id,token,created_at,last_seen) VALUES(?,?,?,?)")->execute([(int)$u['id'], $token, $t, $t]);
            $db->prepare("DELETE FROM user_sessions WHERE user_id=? AND id NOT IN (SELECT id FROM user_sessions WHERE user_id=? ORDER BY last_seen DESC LIMIT 10)")->execute([(int)$u['id'], (int)$u['id']]);
            $db->exec('COMMIT');
        } catch (Exception $e) {
            try { $db->exec('ROLLBACK'); } catch (Exception $e2) {}
            apiFail('Ошибка сохранения сессии: ' . $e->getMessage());
        }
        apiOk(['user' => ['id'=>(int)$u['id'],'name'=>$u['name'],'avatar'=>(string)($u['avatar']??''),'status'=>(string)($u['status']??'online'),'validated'=>$validatedVal,'globalRole'=>(string)($u['global_role']??'')], 'token' => $token]);
    }


    // ══ public invite info — available before login/register ═════
    if ($action === 'get_invite_info') {
        $code = normalizeInviteCode($d['code'] ?? '');
        if (!$code) apiFail('Неверный код приглашения');
        $s = $db->prepare("SELECT i.*,s.name AS server_name,s.description AS server_desc,s.icon AS server_icon,u.name AS creator_name FROM server_invites i JOIN servers s ON s.id=i.server_id JOIN users u ON u.id=i.creator_id WHERE i.code=?");
        $s->execute([$code]); $inv = $s->fetch();
        if (!$inv) apiFail('Приглашение не найдено');
        if ($inv['expires_at'] > 0 && (int)$inv['expires_at'] < nowSec()) apiFail('Срок действия приглашения истёк');
        if ($inv['max_uses'] > 0 && (int)$inv['uses'] >= (int)$inv['max_uses']) apiFail('Лимит использований исчерпан');
        $cnt = $db->prepare("SELECT COUNT(*) FROM server_members WHERE server_id=? AND is_member=1"); $cnt->execute([(int)$inv['server_id']]);
        apiOk(['invite'=>[
            'code'=>(string)$inv['code'],
            'link'=>inviteLink((string)$inv['code']),
            'serverId'=>(int)$inv['server_id'],
            'serverName'=>(string)$inv['server_name'],
            'serverDesc'=>(string)($inv['server_desc']??''),
            'serverIcon'=>(string)($inv['server_icon']??''),
            'creatorName'=>(string)$inv['creator_name'],
            'maxUses'=>(int)$inv['max_uses'],
            'uses'=>(int)$inv['uses'],
            'expiresAt'=>(int)$inv['expires_at'],
            'memberCount'=>(int)$cnt->fetchColumn()
        ]]);
    }

    // ── AUTH WALL ────────────────────────────────────────────────
    $me = authUser($db, $d);
    if (!$me) apiFail('Не авторизован');
    $meId         = (int)$me['id'];
    $meName       = (string)$me['name'];
    $meValidated  = (int)($me['validated'] ?? 0);
    $meGlobalRole = (string)($me['global_role'] ?? '');

    if (isGloballyBanned($db, $meId)) apiFail('Ваш аккаунт заблокирован');

    // ══ verify_session ═══════════════════════════════════════════
    if ($action === 'verify_session') {
        apiOk(['user' => [
            'id'            => $meId,
            'name'          => $meName,
            'avatar'        => (string)($me['avatar'] ?? ''),
            'status'        => (string)($me['status'] ?? 'online'),
            'validated'     => $meValidated,
            'globalRole'    => $meGlobalRole,
            'termsAccepted' => (int)($me['terms_accepted'] ?? 1),
        ]]);
    }

    // ══ logout ═══════════════════════════════════════════════════
    if ($action === 'logout') {
        $tok = (string)($d['token'] ?? '');
        if ($tok) $db->prepare("DELETE FROM user_sessions WHERE user_id=? AND token=?")->execute([$meId, $tok]);
        $db->prepare("UPDATE users SET status='offline',last_seen=? WHERE id=?")->execute([nowSec() - 200, $meId]);
        $db->prepare("DELETE FROM voice_participants WHERE user_id=?")->execute([$meId]);
        $db->prepare("DELETE FROM typing_signals WHERE user_id=?")->execute([$meId]);
        apiOk([]);
    }


    // ══ link_preview ════════════════════════════════════════════
    if ($action === 'link_preview') {
        $url = trim((string)($d['url'] ?? ''));
        if ($url === '') apiFail('URL пустой');
        $res = getLinkPreview($db, $url);
        if (empty($res['ok'])) apiFail((string)($res['error'] ?? 'Нет предпросмотра'));
        apiOk(['preview' => $res['preview']]);
    }

    // ══ heartbeat ════════════════════════════════════════════════
