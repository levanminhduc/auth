When asked about the codebase, project structure, or to find code, always use the context-engine MCP tool (codebase-retrieval) in the root workspace first before reading individual files. Use `codebase-retrieval` instead of the Explore subagent for codebase exploration and search tasks.

When you need to read a specific file but don't know the exact line range, use the file-retrieval MCP tool instead of reading the entire file. Describe what information you need and it returns only the relevant snippets with line numbers. Use the Read tool with the returned line ranges (expanded as needed) to get current content before making edits.

# Repo

authV2 = package shared-auth (`src/`, namespace `Hoatho\SharedAuth\`) + app login (`public/`). Gom AUTHENTICATION dùng chung cho các dự án cùng host trên Laragon; AUTHORIZATION (role/`vai_tro`) ở lại từng dự án. Package nhận `mysqli` từ ngoài, KHÔNG tự quản DB connection.

Đã tích hợp: **lichkehoach**, **nhansuhtdb** (Giai đoạn 1), **sangkienct**, **khsanxuat** (nhân rộng). Hợp đồng mỗi dự án còn 2 điểm chạm:
- `SessionBootstrap::start()` — hoặc `configure()` qua `auto_prepend_file` (`prepend.php`) cho dự án còn nhiều `session_start()` rải rác — thay mọi `session_start()`.
- `(new Guard($publicPaths))->requireLogin()` thay các check `$_SESSION` rải rác.

Port dự án tiếp theo: dùng skill `port-to-authv2` (`.claude/skills/port-to-authv2/`) — kèm script quét dự án và script smoke test HTTP.

Nâng cấp plaintext→bcrypt bật/tắt bằng env `SHARED_AUTH_UPGRADE_ON_LOGIN`, **TẮT suốt rollout**; chỉ bật khi MỌI dự án đã tích hợp (Giai đoạn 2). Nguồn thực thi/lộ trình: `.agents/.dd/specs/shared-auth/tasks.md`.

CSS trang login build từ `resources/css/app.css` bằng `build-css.ps1` (Tailwind v4 standalone, pin version trong script); output `public/assets/css/app.css` commit vào repo — sửa view/JS xong phải build lại.

# Code style

Không viết comment trừ khi giải thích logic quan trọng hoặc không hiển nhiên (bất biến bảo mật, lý do WHY một quyết định). KHÔNG comment mô tả lại điều code đã nói rõ, KHÔNG header trang trí, KHÔNG docblock chỉ nhắc lại tên tham số/kiểu.
