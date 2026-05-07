
  </div><!-- end pc-content -->
</div><!-- end pc-container -->

<!-- [ Footer ] start -->
<footer class="pc-footer">
  <div class="footer-wrapper container-fluid">
    <div class="row">
      <div class="col my-1">
        <p class="m-0 text-muted">Blue Bird Waterfowl MRP</p>
      </div>
    </div>
  </div>
</footer>
<!-- [ Footer ] end -->

<!-- [Berry JS] -->
<script src="/berry/assets/js/plugins/popper.min.js"></script>
<script src="/berry/assets/js/plugins/simplebar.min.js"></script>
<script src="/berry/assets/js/plugins/bootstrap.min.js"></script>
<script src="/berry/assets/js/plugins/feather.min.js"></script>
<script src="/berry/assets/js/fonts/custom-font.js"></script>
<script src="/berry/assets/js/script.js"></script>
<script src="/berry/assets/js/theme.js"></script>
<script>
  layout_change('light');
  font_change('Inter');
  change_box_container('false');
  layout_caption_change('true');
  layout_rtl_change('false');
  preset_change('preset-1');
</script>
<script>
  // Keep all submenus always expanded, prevent Berry from collapsing them
  function forceSubmenusOpen() {
    document.querySelectorAll('.pc-hasmenu').forEach(function(el) {
      el.classList.add('active');
      var sub = el.querySelector('.pc-submenu');
      if (sub) { sub.style.display = 'block'; sub.style.removeProperty('height'); }
    });
  }
  forceSubmenusOpen();

  // Strip Berry's click handler and replace with no-op to prevent collapsing
  document.querySelectorAll('.pc-hasmenu > .pc-link').forEach(function(link) {
    var fresh = link.cloneNode(true);
    fresh.addEventListener('click', function(e) { e.preventDefault(); forceSubmenusOpen(); });
    link.parentNode.replaceChild(fresh, link);
  });
</script>

</body>
</html>
