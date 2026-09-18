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

  // Falls back to geocoding a typed address via /api/v1/geocode (OpenStreetMap
  // Nominatim, proxied server-side — see GeocodeController) when the caller
  // didn't already have coordinates. Without this, pickup/destination stay
  // at (0,0) and TripController::location's distance/ETA math is silently
  // wrong — see MVP_STATUS.md checklist item 3.
  async function geocode(address) {
    if (!address) return null;
    try {
      const res = await fetch(`/api/v1/geocode?q=${encodeURIComponent(address)}`);
      if (!res.ok) return null;
      const data = await res.json();
      return data.found ? { lat: data.lat, lng: data.lng } : null;
    } catch {
      return null;
    }
  }

  document.getElementById("trip-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const formData = new FormData(event.target);
    const resultEl = document.getElementById("request-result");
    const submitBtn = event.target.querySelector('button[type="submit"]');

    submitBtn.disabled = true;
    resultEl.textContent = "Locating pickup and destination…";

    const pickupAddress = formData.get("pickup_address");
    const destinationAddress = formData.get("destination_address");

    const resolvedPickup = pickup ?? await geocode(pickupAddress);
    const resolvedDestination = await geocode(destinationAddress);

    try {
      const res = await fetch("/api/v1/trips", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          trip_type: formData.get("trip_type"),
          pickup_address: pickupAddress,
          pickup_lat: resolvedPickup?.lat ?? 0,
          pickup_lng: resolvedPickup?.lng ?? 0,
          destination_address: destinationAddress,
          destination_lat: resolvedDestination?.lat ?? 0,
          destination_lng: resolvedDestination?.lng ?? 0,
          recipient_phone_number: formData.get("recipient_phone_number") || null,
        }),
      });
      const data = await res.json();

      if (res.status === 401) {
        resultEl.innerHTML = 'Please <a href="/login">log in</a> or <a href="/signup">sign up</a> to request a ride.';
        return;
      }
      if (!res.ok) {
        resultEl.textContent = "Request failed: " + (data.error || "unknown error");
        return;
      }

      const dispatchNote = data.dispatch_status === "no_riders_available"
        ? " No riders are available near you right now — we'll keep looking."
        : " A nearby rider has been offered your trip.";
      resultEl.innerHTML = `Trip #${data.id} requested (status: ${data.status}).${dispatchNote} <a href="/trips/${data.id}">Track it</a>`;
    } catch (e) {
      resultEl.textContent = "Network error: " + e.message;
    } finally {
      submitBtn.disabled = false;
    }
  });
</script>
