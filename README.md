# Top Organic | منصة ERP هجينة لإدارة سلاسل المطاعم

An **Enterprise Hybrid Restaurant ERP Platform** for large restaurant chains in Iraq,
built with **Laravel 12 / PHP 8.3** (backend) and **Flutter** (POS / KDS / mobile).

منصة ERP مؤسسية هجينة لإدارة سلاسل المطاعم الكبرى في العراق، مبنية باستخدام
**Laravel 12 / PHP 8.3** للواجهة الخلفية و**Flutter** لنقاط البيع وشاشات المطبخ والتطبيقات.

---

## Context | السياق

- **Region:** Iraq-first — Arabic (RTL) primary, English (LTR) secondary.
- **Defaults:** IQD currency, `Asia/Baghdad` timezone, `ar-IQ` locale.
- **Model:** Offline-first hybrid — branch edge nodes operate fully offline; cloud is the
  system of record. Multi-branch native, multi-tenant SaaS-ready from day one.

---

## Features | الميزات

- 🍽️ **Menu Management** — إدارة قائمة الطعام (الأصناف، الفئات، الأسعار)
- 🧾 **Order Management** — إدارة الطلبات (إنشاء، تتبّع، حالة الطلب)
- 🪑 **Table Management** — إدارة الطاولات والحجوزات
- 💳 **Billing & Payments** — الفواتير والمدفوعات
- 👥 **Staff Management** — إدارة الموظفين والصلاحيات
- 📊 **Sales Reports** — تقارير المبيعات ولوحة التحكم
- 🔄 **Offline Sync** — مزامنة موثوقة بين الفروع والسحابة

---

## Tech Stack | التقنيات

- **Backend:** PHP 8.3 / Laravel 12
- **Database:** MySQL · **Cache/Queue/Locks:** Redis
- **Realtime:** Laravel Reverb (WebSockets) · **Queues:** Horizon
- **Clients:** Flutter (POS / KDS / mobile) over REST API
- **Delivery:** Docker · GitHub Actions CI/CD

---

## Architecture | المعمارية

The full software architecture (20 sections across 8 documents) lives in
[`docs/architecture/`](docs/architecture/README.md) — requirements, system/modular design,
hybrid & multi-tenant strategy, data/API/cache/storage, sync/queue/WebSocket/notifications,
security/logging/audit, and DevOps/CI-CD.

المعمارية الكاملة موثّقة في [`docs/architecture/`](docs/architecture/README.md).

---

## Status | الحالة

🚧 **Under active development** — يتم بناء هذا النظام تدريجياً بواسطة خبير Cosmos وفق
المعمارية المعتمدة في [`docs/architecture/`](docs/architecture/README.md).
