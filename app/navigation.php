<nav class="app-menu" aria-label="Menu da aplicação">
    <div class="app-menu-inner">
        <ul class="app-menu-links">
            <li><a href="./"<?= $route === '' ? ' aria-current="page"' : '' ?>>Presenças</a></li>
            <?php if ($isAdmin): ?>
                <li><a href="?action=athletes"<?= $route === 'athletes' ? ' aria-current="page"' : '' ?>>Gestão de atletas</a></li>
                <li><a href="?action=users"<?= $route === 'users' ? ' aria-current="page"' : '' ?>>Gestão de utilizadores</a></li>
            <?php endif; ?>
        </ul>
        <form method="post" action="?action=logout">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <button type="submit">Sair</button>
        </form>
    </div>
</nav>
