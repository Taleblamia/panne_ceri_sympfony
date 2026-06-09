const CACHE_NAME = "ticket-panne-ceri-v2";
const FILES_TO_CACHE = [
  "/manifest.json",
  "/icon-512.png"
];

self.addEventListener("install", (event) => {
  // Met en cache les fichiers principaux de l'application.
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(FILES_TO_CACHE))
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  // Supprime les anciens caches si le nom change.
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
    )
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  // Pendant les tests, on prend d'abord la version fraiche du serveur.
  if (event.request.method !== "GET") return;

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        const responseCopy = response.clone();

        caches.open(CACHE_NAME).then((cache) => {
          cache.put(event.request, responseCopy);
        });

        return response;
      })
      .catch(() => caches.match(event.request))
  );
});
