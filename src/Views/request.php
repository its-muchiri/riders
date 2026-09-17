<h1>Request a ride or delivery</h1>

<form id="trip-form" style="max-width: 32rem; display:flex; flex-direction:column; gap: var(--ac-space-4);">
  <label>
    Trip type
    <select name="trip_type" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
      <option value="passenger_motorcycle">Motorcycle (passenger)</option>
      <option value="passenger_car">Car (passenger)</option>
      <option value="parcel_delivery">Parcel delivery</option>
    </select>
  </label>

  <div>
    <button type="button" class="btn btn--secondary" id="use-location-btn">Use my current location for pickup</button>
    <p id="pickup-coords" class="card__meta" style="margin-top: var(--ac-space-2);">Pickup: not set</p>
  </div>

  <label>
    Pickup address
    <input type="text" name="pickup_address" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <label>
    Destination address
    <input type="text" name="destination_address" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <label id="recipient-field" hidden>
    Recipient phone number (for deliveries)
    <input type="tel" name="recipient_phone_number" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <button type="submit" class="btn btn--primary">Request now</button>
</form>

<p id="request-result" class="card__meta" style="margin-top: var(--ac-space-4);"></p>

<script type="module">
  let pickup = null;

  document.querySelector('select[name="trip_type"]').addEventListener("change", (event) => {
    document.getElementById("recipient-field").hidden = event.target.value !== "parcel_delivery";
  });

  document.getElementById("use-location-btn").addEventListener("click", () => {
    if (!navigator.geolocation) {
      document.getElementById("pickup-coords").textContent = "Geolocation is not available in this browser.";
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (position) => {
        pickup = { lat: position.coords.latitude, lng: position.coords.longitude };
        document.getElementById("pickup-coords").textContent = `Pickup: ${pickup.lat.toFixed(5)}, ${pickup.lng.toFixed(5)}`;
      },
      () => { document.getElementById("pickup-coords").textContent = "Could not read your location — enter the pickup address manually below."; }
    );
  });

  document.getElementById("trip-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const formData = new FormData(event.target);
    const resultEl = document.getElementById("request-result");

    try {
      const res = await fetch("/api/v1/trips", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          trip_type: formData.get("trip_type"),
          pickup_address: formData.get("pickup_address"),
          pickup_lat: pickup?.lat ?? 0,
          pickup_lng: pickup?.lng ?? 0,
          destination_address: formData.get("destination_address"),
          destination_lat: 0,
          destination_lng: 0,
          recipient_phone_number: formData.get("recipient_phone_number") || null,
        }),
      });
      const data = await res.json();

      if (!res.ok) {
        // Expected right now: no auth middleware exists yet (see
        // src/Core/Request.php), so customer_id resolves to null and the
        // database rejects the insert — the real, current state of the
        // app, not a demo bug. See src/Controllers/TripController.php.
        resultEl.textContent = "Request failed: " + (data.error || "unknown error") + " — expected until auth middleware and a live database are wired up.";
        return;
      }

      resultEl.innerHTML = `Trip #${data.id} requested (status: ${data.status}). <a href="/trips/${data.id}">Track it</a>`;
    } catch (e) {
      resultEl.textContent = "Network error: " + e.message;
    }
  });
</script>
