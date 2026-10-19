# Product.md — Trang đăng nhập authV2 (thiết kế lại)

Tài liệu đặc tả để **làm lại toàn bộ trang login**: bỏ Bootstrap, chuyển sang **Tailwind CSS
self-host**, và khắc phục 26 vấn đề đã ghi nhận trong đợt review.

Đối tượng đọc: người thiết kế UI và người implement. Mọi mục "BẮT BUỘC" là tiêu chí nghiệm thu.

---

## 1. Bối cảnh

`authV2` là **điểm đăng nhập chung** cho các dự án PHP cùng host trên Laragon
(`lichkehoach`, `nhansuhtdb`, `sangkienct`, `khsanxuat`, …). Người dùng đăng nhập **một lần**
tại đây, mọi dự án dùng chung phiên (cookie `HTSSOSESSID`, path `/`, trượt 30 ngày kể từ thao tác cuối, kèm trần tuyệt đối 30 ngày kể từ lúc đăng nhập).

Trang login vì vậy là **single point of failure** của toàn bộ hệ thống nội bộ: nó hỏng thì
không ai vào được dự án nào. Đây là lý do các ràng buộc về self-host và độ bền dưới đây
không phải "nice to have".

Người dùng thật: nhân viên văn phòng và xưởng may Hoà Thọ Điện Bàn. Máy tính đời cũ, trình
duyệt Chrome/Edge, mạng LAN nội bộ, **có thể không có internet ra ngoài**. Một số máy dùng
chung nhiều người.

---

## 2. Mục tiêu

1. Trang login hoạt động **100% offline** trong LAN — không request nào ra internet.
2. Chặn được brute-force và clickjacking.
3. Đăng nhập sai không làm người dùng phải gõ lại từ đầu.
4. Giao diện gọn, tải nhanh trên máy cũ, dùng được bằng bàn phím và trên điện thoại.
5. Giữ nguyên khả năng đổi branding/bật-tắt tính năng qua `config/login_config.php`.

### Không thuộc phạm vi

- Chức năng "Quên mật khẩu" tự phục hồi (chưa có hạ tầng email) — xem mục 6.9.
- Đăng nhập 2 lớp (2FA).
- Đổi luồng authentication ở tầng package (`CredentialVerifier`, `SessionBootstrap`) — chỉ
  sửa đúng các điểm được liệt kê ở mục 5.
- Thiết kế lại `portal.php` (làm ở đợt sau, nhưng phải dùng chung design token ở mục 7).

---

## 3. Quyết định kỹ thuật

| Hạng mục | Quyết định | Lý do |
|---|---|---|
| CSS framework | **Tailwind CSS v4, build bằng standalone CLI** | Không cần Node/npm trên máy dev; xuất ra 1 file CSS tĩnh self-host |
| Tailwind Play CDN | **CẤM** | Vi phạm yêu cầu offline; đúng là lỗi đang phải sửa |
| Bootstrap | **Gỡ bỏ hoàn toàn** | Đang tải ~200KB rồi ghi đè gần hết |
| Font Awesome | **Gỡ bỏ**, thay bằng inline SVG | ~100KB CSS + webfont chỉ để hiện 4 icon |
| JS framework | Không dùng | Trang chỉ cần ~50 dòng vanilla JS |
| Font chữ | System font stack | Không tải webfont từ ngoài |
| Build output | `public/assets/css/app.css` (commit vào repo) | Deploy chỉ cần copy file, không cần chạy build trên server |

### Pipeline build

    tailwindcss -i resources/css/app.css -o public/assets/css/app.css --minify

- Binary `tailwindcss.exe` tải sẵn, để trong `tools/` (gitignore).
- **File output BẮT BUỘC commit** vào git — server production không chạy build.
- Có script `build-css.ps1` ở gốc repo để chạy lệnh trên.

---

## 4. Cấu trúc file sau khi làm lại

    resources/css/app.css          MỚI  — nguồn Tailwind (@import + @theme + @layer)
    public/assets/css/app.css      MỚI  — output đã build, commit vào git
    public/assets/css/login.css    XOÁ
    public/assets/js/login.js      VIẾT LẠI
    views/login.php                VIẾT LẠI
    views/partials/icon.php        MỚI  — helper trả inline SVG theo tên
    config/login_config.php        MỞ RỘNG (mục 8)
    public/index.php               SỬA (mục 5, 9)
    src/LoginThrottle.php          MỚI  — chống brute-force (mục 5.1)
    src/SessionContract.php        SỬA  — thêm hằng số CSRF (mục 5.4)
    build-css.ps1                  MỚI

---

## 5. Yêu cầu bảo mật (BẮT BUỘC)

### 5.1 Chống brute-force — `src/LoginThrottle.php`

Lớp mới, nhận `mysqli` từ ngoài giống `CredentialVerifier` (package **không** tự quản DB).

Bảng (bản gốc `VARCHAR(190)`; bản hiện hành dưới đây, kèm migration
`database/migrations/2026-10-17_auth_login_attempts_hardening.sql`):

```sql
CREATE TABLE auth_login_attempts (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  identifier   VARCHAR(330) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                           -- 'p:<username>|<ip>', 'ip:<ip>' hoặc 'u:<username>'
  attempted_at DATETIME NOT NULL,
  KEY idx_identifier_time (identifier, attempted_at),
  KEY idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Cột 330 ký tự x 4 byte chỉ vừa khoá chỉ mục khi InnoDB `ROW_FORMAT=DYNAMIC`. Collation giống `user.name`
nên `Admin` và `admin` dùng chung bộ đếm.

Chính sách **lai** (thay cho thiết kế ban đầu "đếm riêng `u:` và `ip:`, 5 lần"):

| Khoá | Ngưỡng sai / cửa sổ | Khoá |
|---|---|---|
| `p:<username>\|<ip>` (cặp) | 5 / 15 phút | 15 phút |
| `ip:<remote_addr>` | 30 / 15 phút | 15 phút |
| `u:<username>` (trần) | 50 / 60 phút | 30 phút |

- Vì sao lai: chỉ đếm theo cặp thì không có giới hạn theo từng tài khoản (dò phân tán từ nhiều IP qua
  mặt được); chỉ đếm toàn cục theo `u:` ở ngưỡng thấp thì ai cũng khoá được tài khoản của người khác.
  Cặp lo dò thường, `u:` là trần cao chống dò phân tán. Đánh đổi đã biết: gửi 50 lần sai vẫn khoá được một
  tài khoản 30 phút.
- Khoá kéo dài đúng `lockout` phút kể từ lần sai chạm ngưỡng, không phụ thuộc cửa sổ. Lần thử bị từ chối
  vì đang khoá không được lưu.
- `begin()` ghi lần thử trước rồi mới đếm (chống đua khi nhiều request song song); `succeed()` xoá bộ đếm
  `p:` và `u:` của username đó, và chỉ bỏ bản ghi `ip:` của chính lần thử thành công.
- Khi bị khoá (kể cả chạm trần `u:`, kể cả mật khẩu đúng): HTTP **429**, thông báo *"Bạn đã nhập sai quá
  nhiều lần. Vui lòng thử lại sau N phút."* — **không** tiết lộ username có tồn tại hay không.
- Chạm trần `u:` ghi `error_log` kèm IP và username (đã thoát ký tự điều khiển) để IT truy vết.
- Username sai UTF-8 hoặc dài quá 255 ký tự, mật khẩu quá 1024 byte: từ chối ngay với thông báo chung
  *"Sai tên đăng nhập hoặc mật khẩu."* trước khi chạm throttle hay DB.
- Dọn bản ghi theo cơ hội (khoảng 5% số request POST) ngay trong `LoginThrottle`, giữ tối thiểu
  `max(24h, window + lockout)`.
- Nếu bảng chưa tồn tại / query lỗi → **fail-open** (cho đăng nhập) + `error_log` cảnh báo.
  Không được để lỗi hạ tầng khoá toàn bộ hệ thống.

### 5.2 CSP + security headers gửi bằng `header()`, không dùng `<meta>`

Chuyển toàn bộ việc phát CSP từ `views/login.php` sang `public/index.php`, **trước mọi output**:

    Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self';
      img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'
    X-Frame-Options: DENY
    X-Content-Type-Options: nosniff
    Referrer-Policy: same-origin
    Cache-Control: no-store, no-cache, must-revalidate
    Pragma: no-cache

Ghi chú:

- `frame-ancestors` **chỉ có hiệu lực khi gửi bằng header** — đây là lý do chính phải đổi.
- Sau khi bỏ Bootstrap/FA, CSP không còn cần whitelist domain ngoài nào → siết về `'self'`.
- **Bỏ `'unsafe-inline'`** khỏi `style-src`: view mới không được có `style=""` hay `<style>`.
- `Cache-Control: no-store` bắt buộc vì HTML chứa CSRF token; máy dùng chung bấm Back không
  được thấy lại form cũ.

### 5.3 Chống clickjacking

Đã phủ bởi `frame-ancestors 'none'` + `X-Frame-Options: DENY` ở 5.2.

### 5.4 Đổi khoá CSRF sang namespace riêng

Session được **chia sẻ** giữa các dự án; khoá `_csrf` quá phổ biến và các dự án khác đang
dùng chính khoá đó (xem `.claude/skills/port-to-authv2/scripts/audit.php`) → đè token của
trang login, gây lỗi "Phiên nhập liệu đã hết hạn" ngẫu nhiên, không tái hiện được.

- Thêm vào `SessionContract`: `public const CSRF = 'shared_auth_csrf';`
- `index.php` dùng `$_SESSION[SessionContract::CSRF]`.
- `SessionContract::clear()` xoá luôn khoá này.
- **Giữ nguyên tên field HTML là `_csrf`**, chỉ đổi khoá lưu trong `$_SESSION` — để
  `tests/http/login_flow_test.php` và `scripts/smoke_test.php` của skill port không phải sửa.

### 5.5 Chống timing attack

Trong `CredentialVerifier::verify()`, khi không tìm thấy user, thiếu cột password, username khớp nhiều
hơn một dòng, hoặc password plaintext sai: chạy một `password_hash($password, PASSWORD_DEFAULT)` (cùng
cost với một lần verify, không phụ thuộc cost của hash đang lưu) rồi mới `return null`, để thời gian
phản hồi của "user không tồn tại" và "sai mật khẩu" xấp xỉ nhau. Tham số password mang
`#[\SensitiveParameter]` để không lọt vào stack trace.

Username khớp hơn một dòng (collation không phân biệt hoa/thường) bị **từ chối** và ghi `error_log`.
Lỗi DB khi xác thực ném `AuthBackendException`; `public/index.php` trả HTTP **503** *"Hệ thống đang bận,
vui lòng thử lại."* thay vì coi là sai mật khẩu. Mặc định `upgradeOnLogin=false` (chỉ bật qua
`SHARED_AUTH_UPGRADE_ON_LOGIN`).

### 5.6 Giữ nguyên (đã đúng, không được làm hỏng)

- PRG: POST thành công → `session_regenerate_id(true)` → `SessionBootstrap::renewCookie()` →
  `unset` CSRF → `SessionContract::set()` → 302.
- Thông báo lỗi gộp *"Sai tên đăng nhập hoặc mật khẩu."*
- `?redirect=` luôn đi qua `RedirectSanitizer::sanitize()`.
- `config/db.php` chỉ `require` khi POST đã qua CSRF và username/mật khẩu hợp lệ về hình thức — GET không mở kết nối DB.
- Escape mọi output bằng `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Form hoạt động được khi JS bị tắt.

### 5.7 Đăng xuất và cấu hình DB

- `logout.php` vẫn là GET (các dự án đang link vào), nhưng từ chối — giữ phiên, 303 về login — khi
  `Sec-Fetch-Site: cross-site` hoặc `Sec-Fetch-Dest` khác `document`; khi trình duyệt không gửi `Sec-Fetch-*`
  thì Origin/Referer lạ hoặc `null` cũng bị từ chối; không có header nào thì cho phép. Hệ quả: dự án ở
  *site khác* (kể cả nằm trong `SHARED_AUTH_ALLOWED_HOSTS`) không đăng xuất bằng link cross-site được.
- `config/db.php` đọc đường dẫn từ env `SHARED_AUTH_DB_CONFIG` (mặc định `C:/xampp/config/db.php`); không
  còn fallback `root`/mật khẩu rỗng, `mysqli_report` bật strict tường minh; thiếu/sai cấu hình thì ghi
  `error_log` chi tiết và trả HTTP 500 với thông báo chung *"Lỗi kết nối cơ sở dữ liệu."*

---

## 6. Yêu cầu UX

### 6.1 Giữ lại giá trị đã nhập khi đăng nhập sai — BẮT BUỘC

Sau POST thất bại, ô `username` phải được điền lại từ `$_POST['username']` (đã escape).
**Không bao giờ** điền lại mật khẩu. Focus tự động nhảy vào ô **mật khẩu** (trừ khi username bị bỏ vì không hợp lệ: khi đó focus ô tên đăng nhập, xem 6.5).

### 6.2 Thông báo lỗi không tự biến mất

Bỏ hoàn toàn cơ chế auto-dismiss với alert lỗi. Bỏ luôn key `ui.alert_auto_dismiss_seconds`
khỏi config. Alert lỗi tồn tại đến khi user submit lại.

### 6.3 Nút submit không được kẹt trạng thái loading

Khi submit: đổi sang spinner + chữ "Đang xử lý…", `disabled`, thêm `aria-busy="true"`.
**BẮT BUỘC** đăng ký `window.addEventListener('pageshow', ...)` để reset nút về trạng thái
ban đầu khi trang được khôi phục từ bfcache (bấm Back). Đây là lỗi khiến user phải F5.

### 6.4 Đổi nhãn "Ghi nhớ đăng nhập"

Nhãn mới: **"Ghi nhớ tên đăng nhập"**. Checkbox chỉ lưu username vào `localStorage`, không
ảnh hưởng thời hạn phiên (phiên vốn trượt 30 ngày cho mọi người, trần tuyệt đối 30 ngày). Nhãn cũ nói sai chức năng.

Thêm dòng ghi chú nhỏ dưới checkbox khi được tick:
*"Chỉ lưu tên đăng nhập trên máy này."*

### 6.5 Không tranh chấp focus

Thứ tự duy nhất, quyết định **phía server** bằng thuộc tính `autofocus`. Quy tắc: username trống → focus
tên đăng nhập, ngược lại → focus mật khẩu; `aria-invalid` chỉ đặt trên ô gây lỗi:

| Tình huống | Ô được focus |
|---|---|
| Lần đầu, không có username lưu sẵn | Tên đăng nhập |
| Có username lưu sẵn (`localStorage`) | Mật khẩu |
| Vừa đăng nhập sai (username còn giữ lại) | Mật khẩu |
| Vừa đăng nhập sai nhưng username bị bỏ (không hợp lệ / không giữ được) | Tên đăng nhập |

JS **không** được gọi `.focus()` sau `DOMContentLoaded` trong trường hợp 1, 3 và 4 — chỉ được
phép trong trường hợp 2 (vì server không biết localStorage). Kèm điều kiện: chỉ focus nếu
`document.activeElement` vẫn là `body` (user chưa gõ gì).

### 6.6 Phân biệt "chưa đăng nhập" và "hết phiên"

`Guard` truyền thêm `?reason=expired` khi đá user ra vì hết phiên. Trang login đọc `reason`:

| `reason` | Hiển thị |
|---|---|
| (không có) | Banner "Đăng nhập để tiếp tục vào **&lt;tên dự án&gt;**" |
| `expired` | Banner cảnh báo vàng: "Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại để tiếp tục vào **&lt;tên dự án&gt;**." |
| `denied` | "Bạn cần đăng nhập để truy cập trang này." |

Giá trị `reason` phải được whitelist cứng (`expired`, `denied`), giá trị lạ → bỏ qua.

### 6.7 Trạng thái loading phải đọc được bằng screen reader

Vùng chứa alert là `<div role="alert">`; vùng trạng thái submit dùng `aria-live="polite"`.

### 6.8 Nút hiện/ẩn mật khẩu

- Icon SVG con mắt / mắt gạch chéo.
- `aria-pressed` phản ánh đúng trạng thái, `aria-label` đổi theo: "Hiện mật khẩu" ↔ "Ẩn mật khẩu".
- Nằm trong tab order, ngay sau ô mật khẩu.
- Vùng chạm tối thiểu 44×44px (WCAG 2.5.8); icon giữ 20px.
- Khi JS tắt: nút **không hiển thị** (được thêm bằng JS, không có sẵn trong HTML).

### 6.9 "Quên mật khẩu"

Mặc định `show_forgot_password => false`. Khi bật, link trỏ tới giá trị
`ui.forgot_password_url` trong config (mặc định là trang hướng dẫn liên hệ IT), **không dùng
`href="#"`**. Nếu `forgot_password_url` rỗng thì không render link.

### 6.10 Không lưu username vĩnh viễn trên máy dùng chung

`localStorage['saved_username']` phải kèm timestamp và **tự hết hạn sau `ui.remember_days`
(mặc định 30) ngày**. Khi user bỏ tick → xoá ngay.

---

## 7. Design system

### 7.1 Design token (`@theme` trong `resources/css/app.css`)

    --color-brand-50:  #eef2ff
    --color-brand-100: #e0e7ff
    --color-brand-500: #5b73e8   /* màu thương hiệu hiện tại, giữ nguyên */
    --color-brand-600: #4a62d7   /* hover */
    --color-brand-700: #3d51b5   /* active / focus ring */
    --color-accent:    #44c4fa   /* điểm cuối gradient nền */

    --color-ink-900: #1f2933     /* tiêu đề */
    --color-ink-700: #3d4465     /* text chính */
    --color-ink-500: #6b7280     /* text phụ, footer */
    --color-line:    #e4e7eb     /* viền */

    --color-danger-bg:   #fef2f2
    --color-danger-line: #fecaca
    --color-danger-ink:  #991b1b
    --color-warn-bg:     #fffbeb
    --color-warn-line:   #fde68a
    --color-warn-ink:    #92400e

    --radius-card:  12px
    --radius-field: 8px
    --shadow-card:  0 10px 30px rgb(0 0 0 / 0.10)

**Ràng buộc contrast (BẮT BUỘC, WCAG AA):**

- Text thường ≥ 4.5:1, text lớn/icon ≥ 3:1.
- `ink-500` trên nền trắng **và** trên nền footer đều phải ≥ 4.5:1 — nếu không đạt thì tối
  màu lại (màu footer hiện tại `#6c757d` trên `#f8f9fa` chỉ ở mức biên).
- Nút brand-500 với chữ trắng ≈ 4.6:1 ✓ (giữ nguyên).
- Không được dùng màu là **tín hiệu duy nhất** — lỗi phải có icon + chữ, không chỉ viền đỏ.

### 7.2 Typography

System stack: `ui-sans-serif, system-ui, "Segoe UI", Roboto, Arial, sans-serif`.

| Vai trò | Cỡ | Trọng lượng |
|---|---|---|
| Tên hệ thống | 20px (≥640px: 22px) | 600 |
| Nhãn field | 14px | 500 |
| Input | 16px | 400 (16px để iOS không tự zoom) |
| Nút chính | 16px | 600 |
| Banner / alert | 14px | 400 |
| Footer | 13px | 400 |

### 7.3 Layout

    ┌──────────────────────────────────────────────┐
    │  nền: gradient brand-500 → accent (135deg)   │
    │                                              │
    │         ┌────────────────────────┐           │
    │         │   [logo 88×88]         │  header   │
    │         │   TÊN HỆ THỐNG         │           │
    │         ├────────────────────────┤           │
    │         │  [alert lỗi nếu có]    │           │
    │         │  [banner ngữ cảnh]     │           │
    │         │  Tên đăng nhập         │  body     │
    │         │  [_________________]   │           │
    │         │  Mật khẩu              │           │
    │         │  [____________][ eye]  │           │
    │         │  [x] Ghi nhớ  Quên MK? │           │
    │         │  [   ĐĂNG NHẬP      ]  │           │
    │         ├────────────────────────┤           │
    │         │  (c) 2026 — Công ty…   │  footer   │
    │         └────────────────────────┘           │
    └──────────────────────────────────────────────┘

- Card: `max-width: 400px`, bo `radius-card`, `shadow-card`, nền trắng.
- Canh giữa bằng **flex + `min-height: 100dvh`**. **CẤM `position: absolute` + inset:0** —
  đây là lỗi hiện tại khiến nội dung bị cắt khi bàn phím ảo bung lên hoặc xoay ngang máy.
- Vùng ngoài card phải scroll được khi nội dung cao hơn viewport.
- Padding: header `24px 24px 12px`, body `24px`, footer `14px`.
- Khoảng cách giữa các field: 20px.

### 7.4 Responsive

| Breakpoint | Hành vi |
|---|---|
| ≥ 640px | Card nổi giữa nền gradient |
| < 640px | Card full-width, nền trắng, bỏ bo góc và shadow, footer đẩy xuống đáy bằng `margin-top:auto`; **vẫn dùng flex column + `min-height:100dvh`**, không `height:100vh` |

### 7.5 Trạng thái tương tác

| Thành phần | Trạng thái | Thể hiện |
|---|---|---|
| Input | focus | viền `brand-500` + ring 3px `brand-500/25` |
| Input | invalid (sau submit) | viền `danger-line` + icon cảnh báo |
| Input | disabled | nền xám nhạt, con trỏ `not-allowed` |
| Nút chính | hover | nền `brand-600` |
| Nút chính | active | nền `brand-700` |
| Nút chính | focus-visible | ring 3px `brand-700/40`, **không bao giờ `outline:none` trần** |
| Nút chính | loading | spinner + "Đang xử lý…", `disabled`, `aria-busy` |
| Link | focus-visible | underline + ring |

### 7.6 Chuyển động

Mọi transition ≤ 150ms. **BẮT BUỘC** có:

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: .01ms !important;
    transition-duration: .01ms !important;
  }
}
```

Spinner khi submit cũng phải tôn trọng quy tắc này (thay bằng chữ tĩnh).

### 7.7 Icon

Inline SVG, `currentColor`, kích thước 16–20px, `aria-hidden="true"`:
`user`, `lock`, `eye`, `eye-off`, `alert-triangle`.

Đặt trong `views/partials/icon.php`, dùng dạng `icon('user', 'w-4 h-4')`.

### 7.8 Class CSS — quy ước đặt tên

- Không được còn class mồ côi. Lỗi hiện tại: CSS viết cho `.login-logo` nhưng HTML dùng
  `.hoatho-logo` → toàn bộ rule logo chết; `.login-page` trên `<body>` không có rule nào.
- Dùng thẳng utility Tailwind trong markup. Chỉ tạo component class trong `@layer components`
  cho 3 thứ lặp lại: `.field-input`, `.btn-primary`, `.alert`.
- Kích thước logo điều khiển bằng class Tailwind **và** attribute `width`/`height` khớp nhau
  (tránh layout shift), lấy từ config.

---

## 8. Config mở rộng — `config/login_config.php`

```php
return [
    'app' => [
        'name'        => 'HỆ THỐNG QUẢN LÝ NHÂN SỰ',
        'company'     => 'CÔNG TY MAY HOÀ THỌ ĐIỆN BÀN',
        'logo_path'   => 'img/logoht.png',
        'logo_width'  => 88,
        'logo_height' => 88,
        'logo_alt'    => 'Logo Hoà Thọ',
    ],
    'ui' => [
        'show_forgot_password' => false,
        'forgot_password_url'  => '',        // rỗng => không render link
        'show_remember_me'     => true,
        'show_copyright'       => true,
        'remember_days'        => 30,
    ],
    'throttle' => [
        'enabled'         => true,
        'max_attempts'    => 5,
        'window_minutes'  => 15,
        'lockout_minutes' => 15,
    ],
];
```

**Bỏ hẳn** khối `csp`: CSP giờ do `index.php` gửi bằng header và là hằng số cứng — không có
lý do chính đáng để cho phép nới lỏng qua config.

**Bỏ** `ui.alert_auto_dismiss_seconds` (xem 6.2).

---

## 9. Hợp đồng giữa controller và view

`public/index.php` phải truyền dữ liệu vào view qua **một mảng `$viewData` tường minh**, thay
vì để view tự `require` config và đọc biến rơi rớt trong scope (lỗi hiện tại).

```php
$viewData = [
    'config'         => require __DIR__ . '/../config/login_config.php',
    'error'          => $error,          // ?string
    'csrf'           => $csrf,           // string
    'redirectTarget' => $redirectTarget, // string
    'originLabel'    => $originLabel,    // ?string
    'reason'         => $reason,         // ?string: 'expired' | 'denied' | null
    'oldUsername'    => $oldUsername,    // string, luôn escape khi in
    'credentialError' => $credentialError, // bool: lỗi do thông tin đăng nhập → đặt aria-invalid
];
require __DIR__ . '/../views/login.php';
```

- View **không** được `require` config, **không** chứa logic ghép chuỗi CSP.
- View mở đầu bằng docblock liệt kê các khoá nó cần.
- Xoá biến chết `$portalUrl` trong `index.php`.

---

## 10. Tiêu chí nghiệm thu

### Chức năng

- [ ] GET trang login trả 200 kèm form và CSRF token.
- [ ] POST thiếu/sai CSRF → từ chối, không tạo phiên.
- [ ] POST đúng → 302 về `?redirect=` đã sanitize, cookie `HTSSOSESSID` path `/`, HttpOnly.
- [ ] Đã đăng nhập mà vào lại `/index.php` → 302 thẳng, không hiện form.
- [ ] Sai mật khẩu → form giữ nguyên username, ô mật khẩu được focus.
- [ ] Cùng một IP sai 5 lần với một username → HTTP 429 + thông báo còn N phút; đúng mật khẩu vẫn bị chặn trong thời gian khoá; IP khác vẫn đăng nhập được username đó.
- [ ] Đăng nhập thành công → bộ đếm `p:` và `u:` của username đó bị xoá.
- [ ] Bảng `auth_login_attempts` không tồn tại → vẫn đăng nhập được (fail-open) + có log.
- [ ] `?reason=expired` hiện banner vàng đúng nội dung; `?reason=<rác>` bị bỏ qua; POST thì không hiện banner `reason`.

### Bảo mật

- [ ] Response header có đủ: CSP (kèm `frame-ancestors 'none'`, `form-action 'self'`),
      `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`,
      `Cache-Control: no-store`.
- [ ] Không còn thẻ `<meta http-equiv="Content-Security-Policy">`.
- [ ] Không có `'unsafe-inline'` trong `style-src`; trang không có `<style>` hay `style=""`.
- [ ] Nhúng trang vào `<iframe>` → bị chặn.
- [ ] Khoá session CSRF là `shared_auth_csrf`, không phải `_csrf`.
- [ ] Thời gian phản hồi "user không tồn tại" ≈ "sai mật khẩu" (chênh < 20ms).

### Offline / hiệu năng

- [ ] **Ngắt internet, trang login vẫn hiển thị và hoạt động đúng 100%.**
- [ ] DevTools Network: 0 request tới domain ngoài (`jsdelivr`, `cdnjs`, `fonts.googleapis`…).
- [ ] Tổng CSS + JS < 30KB (đã minify, chưa gzip).
- [ ] Không có layout shift khi logo tải xong (CLS ≈ 0).

### UX / A11y

- [ ] Bấm Back sau khi submit → nút "Đăng nhập" trở lại bình thường, không kẹt "Đang xử lý…".
- [ ] Thông báo lỗi không tự biến mất.
- [ ] Đi hết form chỉ bằng `Tab`, thứ tự hợp lý, mọi phần tử focus đều **nhìn thấy được** ring.
- [ ] Nút hiện/ẩn mật khẩu có `aria-pressed` và `aria-label` đổi đúng theo trạng thái.
- [ ] Tắt JS: form vẫn submit và đăng nhập được (nút hiện/ẩn mật khẩu biến mất là chấp nhận được).
- [ ] Bật `prefers-reduced-motion`: không còn animation.
- [ ] Toàn bộ cặp màu text/nền đạt ≥ 4.5:1 (kiểm bằng công cụ, không ước lượng).
- [ ] Điện thoại: xoay ngang + bung bàn phím ảo, form vẫn scroll được, không bị cắt.
- [ ] Nhãn checkbox là "Ghi nhớ tên đăng nhập".
- [ ] Không còn link `href="#"` nào.

### Code

- [ ] Không còn tham chiếu tới Bootstrap hay Font Awesome trong toàn repo.
- [ ] Không còn `public/assets/css/login.css`.
- [ ] Không còn class CSS mồ côi (`.hoatho-logo`, `.login-page`) — kiểm bằng grep hai chiều.
- [ ] `views/login.php` không `require` config, chỉ đọc `$viewData`.
- [ ] Không còn biến chết `$portalUrl`.
- [ ] `tests/run.php` xanh toàn bộ, kèm test mới cho `LoginThrottle` và cho luồng 429.

---

## 11. Bảng truy vết 26 lỗi → nơi khắc phục

| # | Lỗi | Mức | Mục xử lý |
|---|---|---|---|
| 1 | Không rate-limit login | Nghiêm trọng | 5.1 |
| 2 | Clickjacking (CSP qua meta, thiếu frame-ancestors) | Nghiêm trọng | 5.2, 5.3 |
| 3 | Phụ thuộc CDN, không SRI | Nghiêm trọng | 3, 10-Offline |
| 4 | Khoá CSRF `_csrf` đụng dự án khác | Nghiêm trọng | 5.4 |
| 5 | Mất username sau khi login sai | Trung bình | 6.1 |
| 6 | Thiếu `form-action` | Trung bình | 5.2 |
| 7 | Thiếu `Cache-Control: no-store` | Trung bình | 5.2 |
| 8 | Timing attack dò username | Trung bình | 5.5 |
| 9 | Nút submit kẹt sau khi bấm Back | Trung bình | 6.3 |
| 10 | Alert lỗi tự biến mất 5s | Trung bình | 6.2 |
| 11 | "Ghi nhớ đăng nhập" sai chức năng | Trung bình | 6.4 |
| 12 | "Quên mật khẩu" link chết | Trung bình | 6.9 |
| 13 | Không phân biệt hết phiên / chưa đăng nhập | Trung bình | 6.6 |
| 14 | Class logo sai → CSS logo chết | Nhỏ | 7.8 |
| 15 | `body.login-page` không có rule | Nhỏ | 7.8 |
| 16 | `.login-wrapper` absolute + inset xung đột | Nhỏ | 7.3, 7.4 |
| 17 | Tải Bootstrap rồi ghi đè gần hết | Nhỏ | 3 |
| 18 | Font Awesome full cho 4 icon | Nhỏ | 3, 7.7 |
| 19 | `style-src 'unsafe-inline'` thừa | Nhỏ | 5.2 |
| 20 | Thiếu `Referrer-Policy`, `X-Content-Type-Options` | Nhỏ | 5.2 |
| 21 | Biến chết `$portalUrl` | Nhỏ | 9 |
| 22 | View tự load config, phụ thuộc biến ngầm | Nhỏ | 9 |
| 23 | Tranh chấp focus autofocus vs JS | Nhỏ | 6.5 |
| 24 | Thiếu `aria-pressed`, `aria-live` | Nhỏ | 6.7, 6.8 |
| 25 | `localStorage` lưu username vĩnh viễn | Nhỏ | 6.10 |
| 26 | Thiếu `prefers-reduced-motion` | Nhỏ | 7.6 |

---

## 12. Thứ tự triển khai đề xuất

1. Dựng pipeline Tailwind + `resources/css/app.css` với design token (mục 3, 7.1).
2. Viết lại `views/login.php` + `views/partials/icon.php` bằng utility Tailwind (mục 7).
3. Sửa `public/index.php`: headers, `$viewData`, `oldUsername`, `reason` (mục 5.2, 9, 6.1, 6.6).
4. Viết lại `public/assets/js/login.js` (mục 6.3, 6.5, 6.8, 6.10).
5. `SessionContract::CSRF` + cập nhật test (mục 5.4).
6. `LoginThrottle` + migration bảng + test (mục 5.1).
7. Timing-safe trong `CredentialVerifier` (mục 5.5).
8. Xoá `login.css`, gỡ mọi tham chiếu Bootstrap/FA, chạy `tests/run.php`.
9. Đối chiếu toàn bộ checklist mục 10.
