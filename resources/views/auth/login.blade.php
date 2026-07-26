<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>تسجيل الدخول - Top Organic</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Cairo', sans-serif; background: linear-gradient(160deg, #1B5E20, #2E7D32); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .card { background: #fff; border-radius: 14px; padding: 40px 36px; width: 380px; border-top: 4px solid #8BC34A; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
    .logo { text-align: center; font-size: 24px; font-weight: 800; color: #1B5E20; margin-bottom: 28px; }
    label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
    input { width: 100%; padding: 10px 14px; border: 1.5px solid #d1d5db; border-radius: 8px; font-family: 'Cairo', sans-serif; font-size: 14px; outline: none; transition: border-color .2s; }
    input:focus { border-color: #2E7D32; }
    .field { margin-bottom: 18px; }
    .btn { width: 100%; padding: 12px; background: #2E7D32; color: #fff; border: none; border-radius: 8px; font-family: 'Cairo', sans-serif; font-size: 15px; font-weight: 700; cursor: pointer; transition: background .2s; margin-top: 8px; }
    .btn:hover { background: #1B5E20; }
    .btn:disabled { background: #9ca3af; cursor: not-allowed; }
    .error { background: #fee2e2; color: #dc2626; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 16px; display: none; }
    .error.show { display: block; }
    .spinner { display: none; }
    .btn.loading .spinner { display: inline-block; }
    .btn.loading .btn-text { display: none; }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo">🌿 Top Organic</div>

    <div class="error" id="error-box"></div>

    <form id="login-form">
      <input type="hidden" name="tenant_slug" value="top-organic">

      <div class="field">
        <label>البريد الإلكتروني أو الهاتف</label>
        <input type="text" name="identifier" id="identifier" required autofocus placeholder="admin@example.com">
      </div>

      <div class="field">
        <label>كلمة المرور</label>
        <input type="password" name="password" id="password" required placeholder="••••••••">
      </div>

      <button type="submit" class="btn" id="submit-btn">
        <span class="btn-text">دخول</span>
        <span class="spinner">جارٍ التحقق...</span>
      </button>
    </form>
  </div>

  <script>
    const form = document.getElementById('login-form');
    const errorBox = document.getElementById('error-box');
    const btn = document.getElementById('submit-btn');

    form.addEventListener('submit', async function(e) {
      e.preventDefault();
      errorBox.className = 'error';
      btn.classList.add('loading');
      btn.disabled = true;

      const data = {
        tenant_slug: form.tenant_slug.value,
        identifier: form.identifier.value,
        password: form.password.value,
      };

      try {
        const res = await fetch('/login', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
          body: JSON.stringify(data),
        });

        const json = await res.json();

        if (res.ok && json.data?.authenticated) {
          window.location.href = '/dashboard';
        } else {
          const msg = json.error?.message || json.message || 'خطأ في بيانات الدخول';
          errorBox.textContent = msg;
          errorBox.className = 'error show';
          btn.classList.remove('loading');
          btn.disabled = false;
        }
      } catch (err) {
        errorBox.textContent = 'حدث خطأ في الاتصال. حاول مرة أخرى.';
        errorBox.className = 'error show';
        btn.classList.remove('loading');
        btn.disabled = false;
      }
    });
  </script>
</body>
</html>
