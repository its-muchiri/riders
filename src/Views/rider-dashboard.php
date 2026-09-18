<?php
/** @var bool $notARider */
/** @var string|null $status */
use Rider\Core\View;
?>
<h1>Rider dashboard</h1>

<?php if (!empty($notARider)): ?>
  <p class="card__meta">This account was created as a customer, not a rider. <a href="/signup">Sign up for a rider account</a> to start riding.</p>
<?php elseif (($status ?? '') !== 'active'): ?>
  <p class="card__meta">
    Your account is pending KYC verification. Once a platform admin approves your documents you'll be able to go online here.
    Haven't submitted yet? <a href="/riders/onboard">Complete onboarding</a>.
  </p>
<?php else: ?>
  <div style="display:flex; align-items:center; gap: var(--ac-space-4); flex-wrap:wrap;">
    <button type="button" id="availability-toggle" class="btn btn--primary">Go online</button>
    <span id="availability-status" class="card__meta">Offline — you won't receive trip offers.</span>
  </div>
  <p id="location-note" class="card__meta" style="margin-top: var(--ac-space-2);"></p>

  <h2 style="margin-top: var(--ac-space-8);">Trip offers</h2>
  <div id="offers-list" style="display:flex; flex-direction:column; gap: var(--ac-space-4); margin-top: var(--ac-space-4);">
    <p class="card__meta">Go online to start receiving trip offers.</p>
  </div>

  <h2 style="margin-top: var(--ac-space-8);">Earnings</h2>
  <div id="earnings-totals" class="card__meta" style="margin-top: var(--ac-space-2);">Loading earnings…</div>
  <ul id="earnings-list" style="list-style:none; padding:0; margin-top: var(--ac-space-3); display:flex; flex-direction:column; gap: var(--ac-space-2);"></ul>

  <script type="module">
    const toggleBtn = document.getElementById('availability-toggle');
    const statusEl = document.getElementById('availability-status');
    const locationNote = document.getElementById('location-note');
    const offersList = document.getElementById('offers-list');

    let isOnline = false;
    let lastCoords = null;
    let watchId = null;
    let pingTimer = null;
    let offersTimer = null;

    function setOnlineUi(online) {
      isOnline = online;
      toggleBtn.textContent = online ? 'Go offline' : 'Go online';
      statusEl.textContent = online
        ? 'Online — waiting for trip offers.'
        : "Offline — you won't receive trip offers.";
    }

    async function setAvailability(online) {
      await fetch('/api/v1/riders/me/availability', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ is_online: online }),
      });
    }

    async function sendPing() {
      if (!lastCoords) return;
      await fetch('/api/v1/riders/me/location-ping', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lat: lastCoords.lat, lng: lastCoords.lng }),
      });
    }

    function renderOffers(offers) {
      offersList.innerHTML = '';
      if (!offers.length) {
        offersList.innerHTML = '<p class="card__meta">No pending offers right now.</p>';
        return;
      }

      for (const offer of offers) {
        const card = document.createElement('div');
        card.className = 'card';
        card.style.padding = 'var(--ac-space-4)';
        card.innerHTML = `
          <p><strong>${offer.trip_type.replace(/_/g, ' ')}</strong> — ~${Number(offer.distance_km).toFixed(1)} km away</p>
          <p class="card__meta">${offer.pickup_address ?? 'Pickup set'} → ${offer.destination_address ?? 'Destination set'}</p>
          <p class="card__meta">Estimated fare: KES ${Number(offer.estimated_fare).toFixed(0)}</p>
          <div style="display:flex; gap: var(--ac-space-3); margin-top: var(--ac-space-3);">
            <button type="button" class="btn btn--primary" data-action="accept" data-trip="${offer.trip_id}">Accept</button>
            <button type="button" class="btn btn--secondary" data-action="decline" data-trip="${offer.trip_id}">Decline</button>
          </div>
        `;
        offersList.appendChild(card);
      }

      offersList.querySelectorAll('button[data-action]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const tripId = btn.dataset.trip;
          const action = btn.dataset.action;
          offersList.querySelectorAll('button').forEach((b) => { b.disabled = true; });

          try {
            const res = await fetch(`/api/v1/trips/${tripId}/${action}`, { method: 'PATCH' });
            const data = await res.json();

            if (!res.ok) {
              alert((data.error || 'That offer is no longer available.'));
              await pollOffers();
              return;
            }

            if (action === 'accept') {
              window.location.href = `/trips/${tripId}`;
            } else {
              await pollOffers();
            }
          } catch (e) {
            alert('Network error: ' + e.message);
          }
        });
      });
    }

    async function pollOffers() {
      try {
        const res = await fetch('/api/v1/riders/me/offers');
        if (!res.ok) return;
        const data = await res.json();
        renderOffers(data.offers || []);
      } catch {
        // Network hiccup — the next poll tick will retry.
      }
    }

    function startWatching() {
      if (!navigator.geolocation) {
        locationNote.textContent = 'Geolocation is not available in this browser — you can still receive offers, but riders should keep location sharing on in production.';
        return;
      }
      watchId = navigator.geolocation.watchPosition(
        (position) => {
          lastCoords = { lat: position.coords.latitude, lng: position.coords.longitude };
          locationNote.textContent = `Sharing location: ${lastCoords.lat.toFixed(5)}, ${lastCoords.lng.toFixed(5)}`;
          sendPing();
        },
        () => { locationNote.textContent = 'Could not read your location — go online again once location access is granted.'; },
        { enableHighAccuracy: true }
      );
      pingTimer = setInterval(sendPing, 8000);
    }

    function stopWatching() {
      if (watchId !== null) navigator.geolocation.clearWatch(watchId);
      if (pingTimer) clearInterval(pingTimer);
      watchId = null;
      pingTimer = null;
      locationNote.textContent = '';
    }

    const kes = (n) => 'KES ' + Number(n).toLocaleString('en-KE', { maximumFractionDigits: 2 });
    const PAYOUT_LABEL = { completed: 'sent to M-Pesa', pending: 'pending', failed: 'failed — contact support' };

    async function loadEarnings() {
      const totalsEl = document.getElementById('earnings-totals');
      const listEl = document.getElementById('earnings-list');
      try {
        const res = await fetch('/api/v1/riders/me/earnings');
        if (!res.ok) { totalsEl.textContent = 'Earnings are unavailable right now.'; return; }
        const data = await res.json();
        totalsEl.textContent = `Paid out: ${kes(data.totals.paid_out)} · Pending payout: ${kes(data.totals.pending_payout)} · Commission deducted: ${kes(data.totals.commission_deducted)}`;
        listEl.innerHTML = '';
        const payouts = data.entries.filter((e) => e.type === 'payout');
        if (!payouts.length) {
          listEl.innerHTML = '<li class="card__meta">No earnings yet — complete a trip to get paid.</li>';
          return;
        }
        for (const entry of payouts) {
          const li = document.createElement('li');
          li.className = 'card__meta';
          li.textContent = `Trip #${entry.trip_id} — ${kes(entry.amount)} (${PAYOUT_LABEL[entry.status] ?? entry.status})`;
          listEl.appendChild(li);
        }
      } catch {
        totalsEl.textContent = 'Earnings are unavailable right now.';
      }
    }
    loadEarnings();

    toggleBtn.addEventListener('click', async () => {
      const nextOnline = !isOnline;
      await setAvailability(nextOnline);
      setOnlineUi(nextOnline);

      if (nextOnline) {
        startWatching();
        pollOffers();
        offersTimer = setInterval(pollOffers, 4000);
      } else {
        stopWatching();
        if (offersTimer) clearInterval(offersTimer);
        offersTimer = null;
        offersList.innerHTML = '<p class="card__meta">Go online to start receiving trip offers.</p>';
      }
    });

    window.addEventListener('beforeunload', () => {
      stopWatching();
      if (offersTimer) clearInterval(offersTimer);
    });
  </script>
<?php endif; ?>
