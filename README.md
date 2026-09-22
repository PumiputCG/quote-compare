# QuoteCompare

## QuoteCompare คืออะไร / About

ระบบเปรียบเทียบราคาของแผนกจัดซื้อ ใช้เทียบราคาสินค้าชิ้นเดียวกันจากหลาย Supplier แล้วเลือกข้อเสนอที่คุ้มค่าที่สุด แทนการกรอก Compare Sheet ใน Excel แล้วเดินเอกสารขอลายเซ็นแบบเดิม

A price comparison system for the purchasing team. It compares quotes for the same items from several suppliers so the best-value offer can be chosen, replacing the old Excel compare sheet that had to be printed and carried around for signatures.

## ทำอะไรได้บ้าง / Features

- กรอกราคาจากหลาย Supplier หลายรายการ และหลายสกุลเงินในเอกสารเดียว
- หน้าเอกสารบนเว็บหน้าตาเหมือน Compare Sheet เดิมที่ฝ่ายจัดซื้อคุ้นเคย
- เซ็นอนุมัติตามลำดับขั้นจริงขององค์กร และตีกลับได้พร้อมระบุเหตุผล
- ออกเลขที่เอกสารตอนเซ็นเลือก Supplier จริง เลขจึงเรียงต่อกันไม่ขาดช่วง
- แนบใบเสนอราคาแยกตามขั้นตอน
- ดึงรายชื่อพนักงานจาก S-Insight อัตโนมัติ

* Enter prices from multiple suppliers, items and currencies in one document
* The on-screen document looks just like the compare sheet purchasing already knew
* Sign-off follows the real approval order, and rejections carry a reason
* Document numbers are issued when a supplier is actually selected, so the sequence has no gaps
* Quotations are attached per stage
* Employee data syncs automatically from S-Insight

## Tech Stack

**Backend:** PHP 8, Laravel 12

**Frontend:** Blade, Tailwind CSS, Vite, Axios

**Database:** MySQL

## ติดตั้ง / Installation

ต้องมี PHP 8.2 ขึ้นไป, Composer, Node.js และ MySQL ก่อนรัน migrate ให้แก้ค่า `DB_*` ใน `.env` ให้ตรงกับฐานข้อมูลในเครื่อง

Requires PHP 8.2+, Composer, Node.js and MySQL. Set the `DB_*` values in `.env` before migrating.

```bash
git clone https://github.com/PumiputCG/quote-compare.git
cd quote-compare
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```
