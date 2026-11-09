# Blog Lisa Style — Hướng dẫn đăng lên GitHub Pages

Blog này chạy miễn phí trên GitHub Pages, dùng chung repo `thanhlamvd/lisa-workspace-studio` (repo đã dùng cho trang Terms / Privacy của app TikTok Lisa Workspace Studio). **Không cần mua domain hay thuê host.**

## Các file trong thư mục này

| File | Nội dung |
|---|---|
| `blog/index.html` | Trang chủ blog Lisa Style (danh sách bài viết, giới thiệu, link 5 kênh) |
| `blog/bai-01-vay-trang-di-lam.html` | Bài viết đầu tiên: váy trắng midi đi làm (09/11/2026) |
| `blog/images/lisa-avatar.jpg` | Ảnh đại diện Lisa (dùng ở trang chủ) |
| `blog/images/vay-trang-di-lam.jpg` | Ảnh bìa bài váy trắng (khung giây 8 của video 09/11) |
| `blog/README.md` | File này — hướng dẫn upload |

## Vo cần làm gì để blog lên mạng?

Vì repo `thanhlamvd/lisa-workspace-studio` là repo của Vo, chỉ Vo mới push được. Các bước:

1. Mở repo trên GitHub: https://github.com/thanhlamvd/lisa-workspace-studio
2. Upload toàn bộ thư mục `blog/` (gồm `index.html`, `bai-01-vay-trang-di-lam.html`, thư mục `images/` với 2 ảnh) vào **thư mục gốc của repo** (branch `main`), sao cho trong repo có đường dẫn `blog/index.html`.
   - Cách dễ nhất: trên trang repo, bấm **Add file → Upload files**, kéo cả thư mục `blog/` vào, rồi **Commit changes**.
   - Hoặc dùng Git trên máy: copy thư mục `blog/` vào bản clone của repo, rồi `git add blog/`, `git commit`, `git push`.
3. Chờ GitHub Pages deploy khoảng 1–2 phút (repo đã bật Pages từ branch `main`, thư mục gốc /(root), HTTPS enforced — cài đặt này đã có sẵn từ lúc làm Terms/Privacy, không cần đổi).
4. Mở thử 2 link dưới đây để kiểm tra.

## Link sau khi đăng

- Trang chủ blog: https://thanhlamvd.github.io/lisa-workspace-studio/blog/
- Bài váy trắng đi làm: https://thanhlamvd.github.io/lisa-workspace-studio/blog/bai-01-vay-trang-di-lam.html

## Thêm bài mới sau này

- Copy `bai-01-vay-trang-di-lam.html` thành file mới, ví dụ `bai-02-ten-bai.html`, sửa nội dung, rồi thêm một mục bài viết mới vào `blog/index.html`.
- Ảnh mới đặt trong `blog/images/` và upload cùng lúc.
- Không cần đổi cấu trúc repo hay cài đặt Pages.

## Lưu ý

- Blog hiện **chưa gắn link affiliate nào**. Các chỗ cần gắn link được đánh dấu rõ `[CHƯA GẮN LINK]` trong bài viết. Chỉ thay placeholder bằng link thật khi Vo đã có link Shopee / TikTok Shop thật và đã kiểm tra giá, size, chất liệu.
- Blog không khẳng định Lisa đã mặc thật hay dùng thử sản phẩm nào — Lisa là nhân vật AI, nội dung là gợi ý phối đồ.
- Các file này hiện chỉ nằm trên máy Pip, **chưa push lên GitHub** — cần Vo upload theo hướng dẫn trên thì blog mới lên mạng.
