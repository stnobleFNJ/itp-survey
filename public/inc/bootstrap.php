<?php
// 공통: 설정, DB 연결, 테이블 자동 생성, 출력 도우미
if (!defined('ITP_APP')) { define('ITP_APP', 1); }

// 서버 설정이 display_errors=On 이어도 경고가 화면에 섞이지 않게 (로그에만 남김)
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('Asia/Seoul');
mb_internal_encoding('UTF-8');

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('inc/config.php 파일이 없습니다. inc/config.sample.php 를 복사해서 만들어 주세요.');
}
$GLOBALS['ITP_CFG'] = require __DIR__ . '/config.php';

function cfg($key, $default = null) {
    return array_key_exists($key, $GLOBALS['ITP_CFG']) ? $GLOBALS['ITP_CFG'][$key] : $default;
}

function g($arr, $key, $default = null) {
    return (is_array($arr) && array_key_exists($key, $arr)) ? $arr[$key] : $default;
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function now() {
    return date('Y-m-d H:i:s');
}

function client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '0.0.0.0';
}

function server_str($key, $len) {
    return isset($_SERVER[$key]) ? mb_substr((string)$_SERVER[$key], 0, $len) : '';
}

function random_hex($bytes) {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($bytes));
    }
    return bin2hex(openssl_random_pseudo_bytes($bytes));
}

/** 테이블 이름 (접두어 포함) */
function T($name) {
    return cfg('table_prefix', 'itp_') . $name;
}

/** DB 연결: ITP_survey DB 를 쓰거나 만들고, 권한이 없으면 대체 DB 사용 */
function db() {
    static $conn = null;
    if ($conn !== null) {
        return $conn;
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $last = null;
    foreach ((array)cfg('db_hosts', array('localhost')) as $host) {
        try {
            $c = mysqli_init();
            $c->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $c->real_connect($host, cfg('db_user'), cfg('db_pass'));
            $conn = $c;
            break;
        } catch (mysqli_sql_exception $e) {
            $last = $e;
        }
    }
    if ($conn === null) {
        throw $last ? $last : new RuntimeException('DB 서버에 연결할 수 없습니다.');
    }
    $conn->set_charset('utf8mb4');

    $name = cfg('db_name', 'ITP_survey');
    $selected = false;
    try {
        $selected = $conn->select_db($name);
    } catch (mysqli_sql_exception $e) {
        try {
            $conn->query('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $name) . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $selected = $conn->select_db($name);
        } catch (mysqli_sql_exception $e2) {
            $selected = false;   // DB 생성 권한 없음 → 대체 DB
        }
    }
    if (!$selected) {
        $conn->select_db(cfg('db_fallback'));
    }
    $GLOBALS['ITP_DB_NAME'] = $conn->query('SELECT DATABASE()')->fetch_row()[0];
    return $conn;
}

/** prepared statement 실행. SELECT 는 행 배열, 그 밖에는 영향받은 행 수 */
function q($sql, $params = array()) {
    $st = db()->prepare($sql);
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : 's';
        }
        $refs = array($types);
        foreach ($params as $k => $v) {
            $refs[] = &$params[$k];
        }
        call_user_func_array(array($st, 'bind_param'), $refs);
    }
    $st->execute();
    $res = $st->get_result();
    if ($res === false) {
        $n = $st->affected_rows;
        $st->close();
        return $n;
    }
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function q1($sql, $params = array()) {
    $rows = q($sql, $params);
    return $rows ? $rows[0] : null;
}

/** 테이블 생성 + 기본 최고 관리자 계정 */
function ensure_schema() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->query("CREATE TABLE IF NOT EXISTS `" . T('survey_response') . "` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        submission_id VARCHAR(64) NOT NULL COMMENT '응답 고유번호(브라우저 생성)',
        status VARCHAR(10) NOT NULL DEFAULT 'progress' COMMENT 'progress=작성중, out=대상외 종료, complete=제출완료',
        survey_version VARCHAR(30) NOT NULL DEFAULT '',
        respondent_type TINYINT NULL COMMENT 'A1: 1=기업 대표응답자, 2=일반 재직자',
        consent TINYINT NULL COMMENT 'P1: 1=동의, 2=비동의',
        company VARCHAR(100) NOT NULL DEFAULT '',
        name VARCHAR(50) NOT NULL DEFAULT '',
        gender VARCHAR(10) NOT NULL DEFAULT '',
        position VARCHAR(50) NOT NULL DEFAULT '',
        phone VARCHAR(50) NOT NULL DEFAULT '',
        email VARCHAR(100) NOT NULL DEFAULT '',
        region SMALLINT NULL COMMENT 'A3 군·구',
        company_type SMALLINT NULL COMMENT 'A4 기업 유형',
        industry SMALLINT NULL COMMENT 'A6 주요 산업 분야',
        job SMALLINT NULL COMMENT 'A7 주된 직무',
        current_q VARCHAR(10) NOT NULL DEFAULT '' COMMENT '마지막으로 보고 있던 문항',
        answered_count SMALLINT NOT NULL DEFAULT 0,
        route_count SMALLINT NOT NULL DEFAULT 0,
        answers MEDIUMTEXT NULL COMMENT '응답 원본(JSON)',
        route TEXT NULL COMMENT '응답 경로 문항 목록(JSON)',
        ip VARCHAR(45) NOT NULL COMMENT '최초 접속 IP',
        last_ip VARCHAR(45) NOT NULL COMMENT '마지막 저장 IP',
        forwarded_for VARCHAR(255) NOT NULL DEFAULT '',
        user_agent VARCHAR(500) NOT NULL DEFAULT '',
        referer VARCHAR(500) NOT NULL DEFAULT '',
        accept_lang VARCHAR(100) NOT NULL DEFAULT '',
        save_count INT UNSIGNED NOT NULL DEFAULT 1,
        started_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_submission (submission_id),
        KEY ix_status (status),
        KEY ix_industry (industry),
        KEY ix_started (started_at),
        KEY ix_completed (completed_at),
        KEY ix_ip (ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='2026 인천 ICT 수요조사 응답'");

    db()->query("CREATE TABLE IF NOT EXISTS `" . T('admin') . "` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        username VARCHAR(30) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        name VARCHAR(50) NOT NULL DEFAULT '',
        email VARCHAR(100) NOT NULL DEFAULT '',
        role VARCHAR(10) NOT NULL DEFAULT 'admin' COMMENT 'super=최고 관리자, admin=관리자',
        is_active TINYINT NOT NULL DEFAULT 1,
        fail_count INT NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        last_login_at DATETIME NULL,
        last_login_ip VARCHAR(45) NOT NULL DEFAULT '',
        created_by VARCHAR(30) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='수요조사 관리자'");

    $cnt = q1('SELECT COUNT(*) AS n FROM `' . T('admin') . '`');
    if ((int)$cnt['n'] === 0) {
        q('INSERT INTO `' . T('admin') . '` (username, password_hash, name, role, is_active, created_by, created_at, updated_at)
           VALUES (?, ?, ?, ?, 1, ?, ?, ?)',
            array(cfg('default_admin_id', 'fnj'), password_hash(cfg('default_admin_pw', 'fnj1234'), PASSWORD_DEFAULT),
                '최고 관리자', 'super', 'system', now(), now()));
    }
}
