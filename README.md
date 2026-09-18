# QuoteCompare — ใบเปรียบเทียบราคา

**TH:** ย้ายใบเปรียบเทียบราคาผู้ขายจาก Excel + ลายเซ็นกระดาษ ขึ้นเว็บ โดยหน้าเอกสารยังเหมือนเดิมเป๊ะ
**EN:** Moving the vendor price-comparison sheet off Excel and paper signatures — without changing the document people already know.

`PHP 8.2` · `Laravel 12` · `MySQL` · `Tailwind CSS 4` · `Vite 7`

---

## 🇹🇭 ภาษาไทย

### โจทย์ที่ยากกว่าที่คิด

ฝ่ายจัดซื้อใช้ Compare Sheet ใน Excel มาตลอด กรอกราคาจากผู้ขายหลายเจ้า ปริ้น เดินให้เซ็นตามลำดับ จบที่ CEO ปัญหาคือหาย ตามสถานะไม่ได้ และกรอกข้อมูลซ้ำทุกครั้ง

แต่สิ่งที่ห้ามทำเด็ดขาดคือ **เปลี่ยนหน้าตาเอกสาร** เพราะคนใช้จนชิน และตัวเอกสารเองก็ต้องใช้อ้างอิงกับผู้ขายจริง ระบบนี้เลยต้องทำให้เหมือนกระดาษเดิมทุกช่อง แต่ข้างหลังเป็นฐานข้อมูลที่ค้นได้

### ทำอะไรได้บ้าง

- **สร้างใบเปรียบเทียบราคา** — กรอกผู้ขายหลายเจ้า หลายรายการ หลายสกุลเงิน
- **ลำดับลายเซ็นจริง** — `DocumentRole` + `step_order` จำลองสายอนุมัติขององค์กร รองรับกรณีที่ขั้นตอนนั้นยังไม่ระบุตัวบุคคล
- **เลขที่เอกสารอัตโนมัติ** — จองเลขตอนที่เซ็นเลือกผู้ขายจริง ไม่ใช่ตอนสร้าง draft (กันเลขกระโดดจากเอกสารที่ไม่ได้ใช้)
- **แนบไฟล์แยกตามขั้นตอน** — ใบเสนอราคาแต่ละรอบเก็บแยกกันด้วย `stage`
- **ตีกลับพร้อมเหตุผล** — ไม่ใช่แค่ปฏิเสธเฉยๆ
- **สิทธิ์ตามตำแหน่ง** — `PositionAccess` คุมว่าใครเห็นอะไร
- **ซิงก์ข้อมูลพนักงาน** — ดึงจากระบบ Insight ผ่าน webhook พร้อม `SyncLog`
- **รหัสสินค้าพร้อมตัวอย่าง** — ช่วยให้คนกรอกเลือกถูก

### จุดที่ภูมิใจ

`defer_pr_number_until_selection_signature` — ตอนแรกออกเลขเอกสารทันทีที่สร้าง draft แล้วพบว่าคนสร้างทิ้งเยอะ ทำให้เลขหาย ไม่ต่อเนื่อง ตรวจสอบยาก เลยย้ายจุดออกเลขไปตอนที่มีการเซ็นเลือกผู้ขายจริง แล้วเขียน migration จองเลขย้อนหลังให้ draft ที่ค้างอยู่ด้วย

### ติดตั้ง

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate && npm run build && php artisan serve
```

---

## 🇬🇧 English

### A harder problem than it looks

Purchasing ran on an Excel compare sheet: type in quotes from several vendors, print it, walk it around for signatures, end at the CEO. Sheets went missing, nobody could check status, and the same data got retyped every time.

The one thing that could not change was **the document itself** — people know it by heart, and it's referenced with real vendors. So the web version reproduces the paper form field for field, while what sits behind it is a searchable database.

### What it does

- **Build a comparison** across multiple vendors, line items, and currencies
- **Real signature chains** — `DocumentRole` plus `step_order` models the actual approval path, including steps where no individual is assigned yet
- **Deferred document numbering** — a number is reserved when someone signs off on a vendor selection, not when a draft is created
- **Stage-scoped attachments** — each round of quotes is stored against its own `stage`
- **Rejection with a reason**, not a bare "no"
- **Position-based access** through `PositionAccess`
- **Employee sync** from the Insight system over a webhook, with a `SyncLog`
- **Item codes with examples** so people pick the right one

### The decision I like most

`defer_pr_number_until_selection_signature`. Numbers were originally issued the moment a draft was created — and abandoned drafts left permanent gaps in the sequence, which made auditing painful. Moving issuance to the vendor-selection signature fixed it, and a companion migration back-filled reservations for drafts already in flight.

### Note

Code only. Database dumps, uploaded quotes, and deploy backups are excluded.
