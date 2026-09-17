<?php
/** @var bool $notARider */
/** @var string|null $status */
use Rider\Core\View;
?>
<h1>Rider onboarding</h1>

<?php if (!empty($notARider)): ?>
  <p class="card__meta">This account was created as a customer, not a rider. <a href="/signup">Sign up for a rider account</a> to submit KYC documents.</p>
<?php elseif (($status ?? '') === 'active'): ?>
  <p class="card__meta">Your account is already verified and active — you can go online from the rider app.</p>
<?php else: ?>
  <p style="max-width: var(--ac-measure); margin-block: var(--ac-space-2) var(--ac-space-6);">
    Given the road-safety stakes of this platform, riders must submit a national ID, a valid driving license,
    and proof of vehicle insurance before going online (this exceeds the platform's generic Tier 1 bar on purpose —
    see <code>open-questions.md</code>). A platform admin reviews and approves each submission.
  </p>

  <form id="onboard-form" style="max-width: 32rem; display:flex; flex-direction:column; gap: var(--ac-space-4);">
    <label>
      Vehicle type
      <select name="vehicle_type" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
        <option value="motorcycle">Motorcycle</option>
        <option value="car">Car</option>
        <option value="tuktuk">Tuk-tuk</option>
      </select>
    </label>
    <label>
      Number plate
      <input type="text" name="plate_number" required placeholder="KMEA 123A" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
    </label>
    <label>
      National ID document reference (photo/scan URL)
      <input type="text" name="national_id_ref" required placeholder="https://..." style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
    </label>
    <label>
      Driving license document reference (photo/scan URL)
      <input type="text" name="driving_license_ref" required placeholder="https://..." style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
    </label>
    <label>
      Insurance certificate document reference (photo/scan URL)
      <input type="text" name="insurance_certificate_ref" required placeholder="https://..." style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
    </label>
    <label>
      Vehicle logbook reference (optional)
      <input type="text" name="vehicle_logbook_ref" placeholder="https://..." style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
    </label>
    <button type="submit" class="btn btn--primary">Submit for review</button>
  </form>

  <p id="onboard-result" class="card__meta" style="margin-top: var(--ac-space-4);"></p>

  <script type="module">
    document.getElementById('onboard-form').addEventListener('submit', async (event) => {
      event.preventDefault();
      const formData = new FormData(event.target);
      const resultEl = document.getElementById('onboard-result');

      const documents = [
        { document_type: 'national_id', file_reference: formData.get('national_id_ref') },
        { document_type: 'driving_license', file_reference: formData.get('driving_license_ref') },
        { document_type: 'insurance_certificate', file_reference: formData.get('insurance_certificate_ref') },
      ];
      const logbookRef = formData.get('vehicle_logbook_ref');
      if (logbookRef) {
        documents.push({ document_type: 'vehicle_logbook', file_reference: logbookRef });
      }

      try {
        const res = await fetch('/api/v1/riders/onboard', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            vehicle_type: formData.get('vehicle_type'),
            plate_number: formData.get('plate_number'),
            documents,
          }),
        });
        const data = await res.json();

        if (!res.ok) {
          resultEl.textContent = 'Submission failed: ' + (data.error || 'unknown error');
          return;
        }

        resultEl.textContent = 'Submitted — your documents are pending admin review. You will be notified once approved.';
        event.target.querySelectorAll('input, select, button').forEach((el) => { el.disabled = true; });
      } catch (e) {
        resultEl.textContent = 'Network error: ' + e.message;
      }
    });
  </script>
<?php endif; ?>
