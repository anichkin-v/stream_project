<section class="auth-card">
    <h1>Вход администратора</h1>
    <form method="post" action="/admin/login">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <label>
            Логин
            <input name="username" type="text" autocomplete="username" required autofocus>
        </label>
        <label>
            Пароль
            <input name="password" type="password" autocomplete="current-password" required>
        </label>
        <button class="button" type="submit">Войти</button>
    </form>
</section>
