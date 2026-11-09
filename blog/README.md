# Blog Lisa Style — Hướng dẫn đăng lên GitHub Pages

Blog này chạy miễn phí trên GitHub Pages, dùng chung repo `thanhlamvd/lisa-workspace-studio` (repo đã dùng cho trang Terms / Privacy của app TikTok Lisa Workspace Studio). **Không cần mua domain hay thuê host.**

## Quy tắc đứng — 2 bài viết mỗi ngày

- **Mỗi ngày đăng 2 bài blog, 1 bài theo mỗi video:** 1 bài theo video buổi sáng + 1 bài theo video buổi tối.
- **Mỗi bài có 3–4 tấm hình:** ảnh bìa (hero) + bộ 3 ảnh dáng (trước / xoay lưng / ngồi) lấy từ ảnh của video hôm đó, đặt trong `blog/images/`.
- Mỗi bài viết theo cùng một mẫu: vì sao hợp ngữ cảnh, cách phối, ai hợp / ai nên cân nhắc, 5 mẹo, FAQ, link xem video cùng ngày. Luôn ghi rõ Lisa là nhân vật AI, không khẳng định đã mặc thật hay dùng thử, không bịa giá hay link — chỗ cần link để `[CHƯA GẮN LINK]`.
- Từ 09/11/2026, Pip tự đẩy bài lên GitHub bằng token đã kết nối (connector `custom.github`, helper `~/workspace/custom-connectors/custom.github/bin/github.py`), không cần Vo upload tay nữa. Mỗi file đẩy riêng bằng lệnh `file-put`, rồi kiểm tra lại bằng `file-get`.

## Các file trong thư mục này

| File | Nội dung |
|---|---|
| `blog/index.html` | Trang chủ blog Lisa Style (danh sách bài viết, giới thiệu, link 5 kênh) |
| `blog/bai-01-vay-trang-di-lam.html` | Bài viết đầu tiên: váy trắng midi đi làm (09/11/2026, sáng) |
| `blog/bai-02-vay-ngu-lua-hong.html` | Bài viết thứ hai: váy ngủ lụa 2 dây hồng phấn (09/11/2026, tối) |
| `blog/images/lisa-avatar.jpg` | Ảnh đại diện Lisa (dùng ở trang chủ) |
| `blog/images/vay-trang-di-lam.jpg` | Ảnh bìa bài váy trắng (khung giây 8 của video 09/11) |
| `blog/images/vay-trang-truoc.jpg`, `vay-trang-xoay.jpg`, `vay-trang-ngoi.jpg` | 3 ảnh dáng bài váy trắng |
| `blog/images/vay-ngu-hong-truoc.jpg`, `vay-ngu-hong-xoay.jpg`, `vay-ngu-hong-ngoi.jpg` | 3 ảnh dáng bài váy ngủ hồng |
| `blog/README.md` | File này — hướng dẫn upload |

## Vo cần làm gì để blog lên mạng?

Blog đã lên mạng từ 09/11/2026. Pip tự đẩy file lên repo bằng token GitHub đã kết nối, không cần Vo upload tay nữa. Nếu token hết hạn hoặc bị thu hồi, quay lại các bước dưới đây để cấp token mới, hoặc upload tay:

1. Mở repo trên GitHub: https://github.com/thanhlamvd/lisa-workspace-studio
2. Upload toàn bộ thư mục `blog/` (gồm `index.html`, các bài viết, thư mục `images/`) vào **thư mục gốc của repo** (branch `main`), sao cho trong repo có đường dẫn `blog/index.html`.
   - Cách dễ nhất: trên trang repo, bấm **Add file → Upload files**, kéo cả thư mục `blog/` vào, rồi **Commit changes**.
   - Hoặc dùng Git trên máy: copy thư mục `blog/` vào bản clone của repo, rồi `git add blog/`, `git commit`, `git push`.
3. Chờ GitHub Pages deploy khoảng 1–2 phút (repo đã bật Pages từ branch `main`, thư mục gốc /(root), HTTPS enforced — cài đặt này đã có sẵn từ lúc làm Terms/Privacy, không cần đổi).
4. Mở thử 2 link dưới đây để kiểm tra.

## Link sau khi đăng

- Trang chủ blog: https://thanhlamvd.github.io/lisa-workspace-studio/blog/
- Bài váy trắng đi làm: https://thanhlamvd.github.io/lisa-workspace-studio/blog/bai-01-vay-trang-di-lam.html
- Bài váy ngủ lụa hồng: https://thanhlamvd.github.io/lisa-workspace-studio/blog/bai-02-vay-ngu-lua-hong.html

## Thêm bài mới sau này

- Copy `bai-01-vay-trang-di-lam.html` thành file mới, ví dụ `bai-03-ten-bai.html`, sửa nội dung, rồi thêm một mục bài viết mới lên đầu danh sách trong `blog/index.html`.
- Ảnh mới đặt trong `blog/images/` và đẩy cùng lúc (mỗi bài 3–4 ảnh: bìa + 3 ảnh dáng).
- Đẩy lên GitHub bằng helper: `~/workspace/custom-connectors/custom.github/bin/github.py file-put thanhlamvd lisa-workspace-studio "blog/<đường dẫn trong repo>" "<đường dẫn file local>" "<commit message>"`, rồi kiểm tra lại bằng `file-get`.
- Không cần đổi cấu trúc repo hay cài đặt Pages.

## Lưu ý

- Blog hiện **chưa gắn link affiliate nào**. Các chỗ cần gắn link được đánh dấu rõ `[CHƯA GẮN LINK]` trong bài viết. Chỉ thay placeholder bằng link thật khi Vo đã có link Shopee / TikTok Shop thật và đã kiểm tra giá, size, chất liệu.
- Blog không khẳng định Lisa đã mặc thật hay dùng thử sản phẩm nào — Lisa là nhân vật AI, nội dung là gợi ý phối đồ.
