<?php
if (!isset($pageTitle)) $pageTitle = 'Admin';
?>
    </div>
</main>
</div>
<script src="/js/admin.js?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/js/admin.js') ?: time() ?>"></script>
</body></html>
