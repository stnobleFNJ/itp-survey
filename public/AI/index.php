<?php
// 유입 경로별 설문 주소 (/AX/, /AI/, /AWS/)
// 설문 본문은 inc/survey_channel.html (기본 설문 + A1 '인천 ICT IS 교육 기수강생' 보기 + 유입 경로 전송).
// 기본 주소(/itp/)의 index.html 은 그대로 두고, 세 폴더만 이 파일을 사용함.
// 이 주소로 들어온 응답은 관리자 화면에서 폴더 이름(AX·AI·AWS)으로 구분됨.
header('Content-Type: text/html; charset=utf-8');
$html = file_get_contents(__DIR__ . '/../inc/survey_channel.html');
// 설문 안의 상대 주소(api/submit.php 등)가 상위 폴더 기준으로 동작하도록
echo preg_replace('/<head>/i', "<head>\n<base href=\"../\">", $html, 1);
