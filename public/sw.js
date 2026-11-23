self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    const home = new URL(self.registration.scope);
    // Only the home navigation gets a generic offline response. Private content is never cached.
    if (event.request.mode !== 'navigate' || url.href !== home.href) return;

    event.respondWith(fetch(event.request).catch(() => new Response(
        '<!doctype html><html lang="pt-PT"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sem ligação</title><h1>Sem ligação</h1><p>Ligue-se à internet e atualize a página para registar presenças.</p></html>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } },
    )));
});
