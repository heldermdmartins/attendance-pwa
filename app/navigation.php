<nav class="app-menu" aria-label="Menu da aplicação">
    <div class="app-menu-inner">
        <button class="app-menu-toggle" type="button" aria-expanded="false" aria-controls="app-menu-panel" hidden>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M3 6h18M3 12h18M3 18h18"/>
            </svg>
            Menu
        </button>
        <div class="app-menu-panel" id="app-menu-panel">
            <ul class="app-menu-links">
                <li><a href="./"<?= $route === '' ? ' aria-current="page"' : '' ?>>Presenças</a></li>
                <?php if ($canViewAthletes): ?>
                    <li><a href="?action=athletes"<?= $route === 'athletes' ? ' aria-current="page"' : '' ?>><?= $isAdmin ? 'Gestão de atletas' : 'Atletas' ?></a></li>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <li><a href="?action=users"<?= $route === 'users' ? ' aria-current="page"' : '' ?>>Gestão de utilizadores</a></li>
                    <li><a href="?action=activity"<?= $route === 'activity' ? ' aria-current="page"' : '' ?>>Registo de atividade</a></li>
                <?php endif; ?>
            </ul>
            <form method="post" action="?action=logout">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button type="submit">Sair</button>
            </form>
        </div>
    </div>
</nav>
