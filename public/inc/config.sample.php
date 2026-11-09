<?php
// 이 파일을 config.php 로 복사한 뒤 값을 채우세요. (config.php 는 git 에 올리지 않습니다)
if (!defined('ITP_APP')) { http_response_code(403); exit; }

return array(
    // 접속할 DB 서버 (위에서부터 차례로 시도)
    //  - cafe24 웹서버에서는 localhost 로 접속됩니다.
    //  - 사무실 PC 에서 시험할 때는 아이디.cafe24.com 으로 접속됩니다.
    'db_hosts'     => array('localhost', '아이디.cafe24.com'),
    'db_user'      => '아이디',
    'db_pass'      => '비밀번호',

    // 먼저 이 DB 를 쓰고, 없으면 만들어 봅니다.
    'db_name'      => 'ITP_survey',
    // DB 를 만들 권한이 없으면(cafe24 기본 호스팅) 이 DB 안에 테이블을 만듭니다.
    'db_fallback'  => '아이디',

    // 테이블 이름 앞에 붙는 접두어 (공용 DB 안에서 다른 테이블과 섞이지 않도록)
    'table_prefix' => 'itp_',

    // 최초 실행 시 만들어지는 최고 관리자 계정 (로그인 후 반드시 비밀번호를 바꾸세요)
    'default_admin_id' => 'fnj',
    'default_admin_pw' => 'fnj1234',

    // 같은 IP 에서 1시간 동안 새로 시작할 수 있는 응답 수 (무분별한 반복 제출 방지)
    'max_new_per_ip_hour' => 200,
);
