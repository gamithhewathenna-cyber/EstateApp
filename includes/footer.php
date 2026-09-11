  </main>
</div>
</div><!-- .app-wrapper -->

<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>

<!-- SWITCH ESTATE MODAL -->
<div class="modal-overlay" id="modal-switch-estate" onclick="if(event.target===this)closeModal('modal-switch-estate')">
  <div class="modal-box" style="max-width:460px">
    <div class="modal-title"><i class="ti ti-switch-horizontal"></i> Switch Estate</div>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:4px">
      <?php foreach ($switchableEstates as $se): $isCurrent = ((int)$se['id'] === (int)$activeEstateId); ?>
      <form method="POST" action="<?= BASE_URL ?>/estate-picker.php" onsubmit="return fmSwitchEstate(this)">
        <input type="hidden" name="estate_id" value="<?= $se['id'] ?>">
        <button type="submit" <?= $isCurrent ? 'disabled' : '' ?>
                style="font-family:inherit;display:flex;align-items:center;gap:12px;width:100%;text-align:left;padding:12px 14px;border-radius:var(--radius-md);border:1.5px solid <?= $isCurrent ? 'var(--green-400)' : '#e8ede5' ?>;background:<?= $isCurrent ? 'var(--green-50)' : '#fff' ?>;cursor:<?= $isCurrent ? 'default' : 'pointer' ?>;transition:background .15s,border-color .15s"
                <?php if (!$isCurrent): ?>
                onmouseover="this.style.background='var(--green-50)';this.style.borderColor='var(--green-200)'"
                onmouseout="this.style.background='#fff';this.style.borderColor='#e8ede5'"
                <?php endif; ?>>
          <div style="width:36px;height:36px;border-radius:9px;background:var(--green-50);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden">
            <?php if (!empty($se['logo_file'])): ?>
            <img src="<?= BASE_URL ?>/assets/img/<?= sanitize($se['logo_file']) ?>" style="width:100%;height:100%;object-fit:contain;padding:4px">
            <?php else: ?>
            <i class="ti ti-trees" style="color:var(--green-600);font-size:18px"></i>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:700;color:var(--green-900);display:flex;align-items:center;gap:6px">
              <?= sanitize($se['name']) ?>
              <?php if ($se['is_default']): ?><span style="font-size:9px;font-weight:700;background:var(--green-100);color:var(--green-800);padding:1px 6px;border-radius:10px">DEFAULT</span><?php endif; ?>
            </div>
            <?php if ($se['location']): ?>
            <div style="font-size:11px;color:var(--gray-400)"><i class="ti ti-map-pin" style="font-size:10px"></i> <?= sanitize($se['location']) ?></div>
            <?php endif; ?>
          </div>
          <?php if ($isCurrent): ?>
          <span style="font-size:10px;font-weight:700;color:var(--green-600);display:flex;align-items:center;gap:3px;flex-shrink:0"><i class="ti ti-check"></i> Current</span>
          <?php else: ?>
          <i class="ti ti-chevron-right" style="color:var(--gray-400);flex-shrink:0"></i>
          <?php endif; ?>
        </button>
      </form>
      <?php endforeach; ?>
      <?php if (!$switchableEstates): ?>
      <div class="empty-state"><i class="ti ti-trees-off"></i><p>No estates available</p></div>
      <?php endif; ?>
    </div>
    <button type="button" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:14px" onclick="closeModal('modal-switch-estate')">Cancel</button>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script>
// Smooth switch: fade the page out before the form's POST navigates away,
// so the estate change doesn't feel like an abrupt jump.
function fmSwitchEstate(form) {
  var btn = form.querySelector('button[type=submit]');
  if (btn) { btn.style.opacity = '0.6'; btn.style.pointerEvents = 'none'; btn.innerHTML += ' <i class="ti ti-loader-2" style="animation:spin .6s linear infinite"></i>'; }
  var content = document.querySelector('.content');
  if (content) { content.style.transition = 'opacity .25s ease'; content.style.opacity = '0'; }
  return true;
}
</script>
</body>
</html>
