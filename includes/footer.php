        </main><!-- /.content -->
    </div><!-- /.main -->
</div><!-- /.shell -->

<?php /* The application root, so fetch() calls in main.js work from any
         sub-folder without hard-coding a path. */ ?>
<script>
window.LSBMS_BASE = <?= json_encode(BASE_URL) ?>;
// Needed so a script-initiated state change can carry the same
// token a posted form would. Read only by same-origin script:
// another site cannot read this page's body to steal it.
window.LSBMS_CSRF = <?= json_encode(csrf_token()) ?>;
</script>

<script src="<?= e(ASSETS_URL) ?>js/main.js" defer></script>
<script src="<?= e(ASSETS_URL) ?>js/validation.js" defer></script>
</body>
</html>
