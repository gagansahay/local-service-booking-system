        </main><!-- /.content -->
    </div><!-- /.main -->
</div><!-- /.shell -->

<?php /* The application root, so fetch() calls in main.js work from any
         sub-folder without hard-coding a path. */ ?>
<script>window.LSBMS_BASE = <?= json_encode(BASE_URL) ?>;</script>

<script src="<?= e(ASSETS_URL) ?>js/main.js" defer></script>
<script src="<?= e(ASSETS_URL) ?>js/validation.js" defer></script>
</body>
</html>
