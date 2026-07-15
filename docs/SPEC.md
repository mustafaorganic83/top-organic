# Specification | مواصفات نظام إدارة المطاعم

This document describes what the Cosmos expert should build, feature by feature.

هذه الوثيقة تصف ما يجب على خبير Cosmos بناءه، ميزة تلو الأخرى.

---

## 1. Core Setup | الإعداد الأساسي
- Django project `restaurant` with apps: `menu`, `orders`, `tables`, `billing`, `staff`, `reports`.
- Custom user model in `staff` (roles: admin, manager, waiter, cashier, kitchen).
- Django REST Framework for APIs.

## 2. Menu | قائمة الطعام
- Models: `Category`, `MenuItem` (name, description, price, category, is_available, image).
- CRUD via Django Admin + REST API.

## 3. Tables | الطاولات
- Model: `Table` (number, capacity, status: free/occupied/reserved).
- Reservation model with date/time and customer info.

## 4. Orders | الطلبات
- Models: `Order` (table, waiter, status, created_at), `OrderItem` (menu_item, quantity, notes).
- Order statuses: new → preparing → served → paid → cancelled.
- API to create/update orders and change status.

## 5. Billing | الفواتير
- Model: `Invoice` (order, subtotal, tax, discount, total, payment_method, paid_at).
- Generate invoice from an order; support cash/card.

## 6. Staff | الموظفون
- Role-based permissions on all views/APIs.
- Login/logout, staff CRUD (admin only).

## 7. Reports | التقارير
- Daily/weekly/monthly sales totals.
- Top-selling items, revenue by category.
- Simple dashboard page.

---

## Non-functional | متطلبات غير وظيفية
- Tests for each app (models + API).
- Seed/fixtures with sample data.
- README kept up to date.
- Environment variables via `.env` (see `.env.example`).
