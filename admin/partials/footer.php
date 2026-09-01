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
<script src="<?= e(BASE_URL . '/admin/assets/js/admin.js') ?>"></script>
<script src="<?= e(BASE_URL . '/admin/assets/js/richtext.js') ?>"></script>
</body>
</html>
<?php
}
