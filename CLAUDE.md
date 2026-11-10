# itp-survey — 2026 인천 ICT 수요조사 (설문 + 관리자)

순수 PHP(프레임워크 없음) + MariaDB. 상세 구성은 README.md 참고.

## 운영 주소 (cafe24, fnjdev)
- 설문: https://fnjdev.cafe24.com/itp/
- 관리자: https://fnjdev.cafe24.com/itp/admin/ (최고 관리자 `fnj`)
- 서버 `/_itp/` 는 예전 정적 설문(저장 안 됨) 백업. 건드리지 않음.

## 배포
- `public/` 안의 내용 = 서버 `/itp/` 폴더. FTP 로 바뀐 파일만 올림 (`curl -T 파일 ftp://fnjdev.cafe24.com/itp/경로`).
- FTP 접속 직후 위치가 웹 루트(`/www`)이며 운영 중인 회사 사이트가 있음. 루트에 `admin/ api/ inc/ index.html` 이 이미 있으니 절대 루트에 올리지 말 것.
- FTP 명령(RNFR 등)은 `/` 로 시작하는 절대 경로가 실패함 → 상대 경로 사용.
- 접속 정보: `data/fnjdev_cafe24계정정보.txt`, 실제 DB 설정: `public/inc/config.php` (둘 다 git 제외, 내용을 출력하거나 커밋하지 말 것).

## 서버 특이사항 (루트 .htaccess)
- `.html` 도 PHP 로 실행됨 → HTML 안에 `<?` 를 쓰지 말 것.
- `session.auto_start On` → 이미 시작된 세션을 그대로 사용 (`inc/admin.php` 에서 처리).
- `display_errors On` → `inc/bootstrap.php` 에서 화면 출력 끔.
- PHP 버전 미확인 → `??`, 화살표 함수 등 PHP 7.4+ 문법 피하고 PHP 5.6~ 호환으로 작성.

## DB
- 계정에 DB 생성 권한이 없어 `ITP_survey` 대신 `fnjdev` DB 사용, 테이블 접두어 `itp_`.
  - `itp_survey_response` (응답, status: progress / out / complete), `itp_admin` (관리자)
- 사무실 IP 에서는 `fnjdev.cafe24.com` 으로 원격 접속 가능 (로컬 시험도 실서버 DB 를 씀).
- **실제 응답이 들어와 있음.** 시험 데이터는 `submission_id` 를 `test-` 로 시작하게 넣고, 지울 때도 `LIKE 'test-%'` 로만 지울 것. TRUNCATE 금지.

## 설문
- `public/index.html` = `data/인천_ICT_수요조사_온라인설문_v3.3.html` 사본 + 수정(서버 저장, 작성 중 자동 저장, 연락처 자동 하이픈). `data/` 원본은 수정하지 않음.
- 관리자 화면은 `index.html` 안의 `const DATA = {...}` 를 읽어 문항 제목·보기를 표시 (`inc/survey.php`).
- "분야" = A6 주요 산업 분야.
- 응답 사례 = **네이버페이 1만원권** (조사 종료 후 휴대전화로 발송). P1 동의서 문구(목적 ③, 거부 권리, '동의하지 않음' 설명)와 완료 화면 안내가 이 내용으로 맞춰져 있음 → 바꿀 때는 양쪽 모두 수정.
- 완료 화면: `doneSection(consent, phone)` 하나로 실제 제출 화면과 미리보기가 같이 그려짐. 맨 위 노란 "✓ 설문이 완료되었습니다" 박스(가운데 정렬), 그 아래 작은 감사 문구, 사례 안내 박스(동의 여부에 따라 문구 다름).
- 미리보기(저장 안 됨): `?preview=done` (동의함), `?preview=done-no` (동의하지 않음).
- `index.html` 의 DATA(JSON)를 고칠 때 줄바꿈은 `\n` 두 글자로 넣어야 함. 이 환경의 bash heredoc 은 `\\` 를 `\` 로 줄이므로, 수정 스크립트는 Write 로 파일을 만든 뒤 실행하고(`chr(92)+"n"` 사용) 저장 전에 `json.loads` 로 검증할 것.

## 관리자 화면
- 결과 목록: 완료 여부·분야·응답자 유형·기간·검색어 검색, 일자 포함 정렬, CSV 내려받기.
- 성명 클릭 → 응답 내용 팝업(`view.php?id=N&popup=1`, 머리글 없는 화면). 번호 클릭 → 상세 화면.
- 관리자 관리: 같은 페이지에서 등록·수정·삭제. 최고 관리자만 다른 계정 관리·응답 삭제 가능.
- `fnj` 계정은 아직 초기 비밀번호일 수 있음 (화면 상단에 경고 표시됨).

## 로컬 시험
- `cd public && php -S 127.0.0.1:8765 -t .`
- Windows 명령줄 curl 로 한글 JSON 을 보내면 깨짐 → UTF-8 파일로 만들어 `--data-binary @파일`.
- 헤드리스 Chrome 은 창 폭이 500px 미만으로 줄지 않음 (모바일 스크린샷이 잘려 보여도 실제 문제 아님).
- 화면 확인: `C:\Program Files\Google\Chrome\Application\chrome.exe --headless=new --screenshot=... --window-size=900,600 <주소>` 로 캡처 후 이미지 확인.
- 설문 JS 를 고친 뒤에는 `<script>` 내용을 뽑아 `node --check` 로 문법 검사, `grep -c '<?' public/index.html` 이 0 인지 확인 후 업로드.
