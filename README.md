# 2026 인천 ICT 수요조사 (설문 + 관리자)

## 구성

```
public/                 ← cafe24 에 올리는 폴더 (이 안의 내용을 그대로 업로드)
├── index.html          설문 화면 (data/ 원본 v3.3 + 서버 저장 연결)
├── api/submit.php      응답 저장 (작성 중 자동 저장 + 최종 제출)
├── admin/              관리자 화면
│   ├── login.php       로그인
│   ├── index.php       설문조사 결과 (검색·정렬·CSV)
│   ├── view.php        응답 상세
│   ├── export.php      CSV 내려받기
│   └── admins.php      관리자 관리 (등록·수정·삭제)
└── inc/                공통 코드·설정 (브라우저 접근 차단)
    ├── config.php      실제 DB 접속 정보 (git 제외)
    └── config.sample.php
data/                   원본 설문 HTML, 계정 정보 (업로드하지 않음)
```

## 배포 (cafe24)

1. `public/` 안의 파일을 FTP 로 `www/` (또는 그 아래 원하는 폴더)에 올립니다. `inc/config.php` 도 함께 올려야 합니다.
2. `관리자 주소/admin/` 에 처음 접속하면 테이블과 최고 관리자 계정이 자동으로 만들어집니다.
   - 아이디 `fnj` / 비밀번호 `fnj1234` → 로그인 후 바로 비밀번호를 바꾸세요.

## DB

- `ITP_survey` DB 를 먼저 시도하고, 만들 권한이 없으면 `fnjdev` DB 에 테이블을 만듭니다.
  cafe24 기본 호스팅은 DB 생성 권한이 없어 현재 `fnjdev` DB 를 사용합니다.
- 테이블: `itp_survey_response` (응답), `itp_admin` (관리자)

## 설문 수정 시

`public/index.html` 의 문항(DATA)을 고치면 관리자 화면의 문항 제목·보기도 자동으로 따라갑니다.
