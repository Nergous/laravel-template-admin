<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title inertia><?php echo e(config('app.name')); ?></title>

    
    <?php ($favicon = \App\Models\Setting::value('general', 'favicon')); ?>
    <link rel="icon" href="<?php echo e($favicon ?: '/favicon.ico'); ?>">


    
    <script nonce="<?php echo e(\Illuminate\Support\Facades\Vite::cspNonce()); ?>">
        try {
            var t = localStorage.getItem('nergouscit-theme');
            var d = localStorage.getItem('nergouscit-density');
            if (t) document.documentElement.setAttribute('data-theme', t);
            if (d) document.documentElement.setAttribute('data-density', d);
        } catch (e) {}
    </script>

    <?php echo app('Illuminate\Foundation\Vite')(['resources/js/admin/app.js']); ?>
    <?php $__inertiaSsrResponse = app(\Inertia\Ssr\SsrState::class)->setPage($page)->dispatch();  if ($__inertiaSsrResponse) { echo $__inertiaSsrResponse->head; } ?>
</head>

<body class="nergouscit-reset">
    <?php $__inertiaSsrResponse = app(\Inertia\Ssr\SsrState::class)->setPage($page)->dispatch();  if ($__inertiaSsrResponse) { echo $__inertiaSsrResponse->body; } else { ?><script data-page="app" type="application/json"><?php echo json_encode($page); ?></script><div id="app"></div><?php } ?>
</body>

</html>
<?php /**PATH D:\Files\Work\laravel-template-admin\resources\views/admin.blade.php ENDPATH**/ ?>