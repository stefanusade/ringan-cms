<?php
/**
 * Layout footer admin.
 */

declare(strict_types=1);

function admin_footer(): void
{
    ?>
  <footer class="footer">
    Ringan CMS — Headless CMS ringan &amp; aman
  </footer>
  </main>
</div>
<script src="<?= e(BASE_URL . '/admin/assets/js/admin.js?v=' . RINGAN_CMS_VERSION) ?>"></script>
<script src="<?= e(BASE_URL . '/admin/assets/js/richtext.js?v=' . RINGAN_CMS_VERSION) ?>"></script>
</body>
</html>
<?php
}
