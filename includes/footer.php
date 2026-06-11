<?php
// Footer include for all pages
?>
</div>
<script>
document.addEventListener('click', function (event) {
    const source = event.target instanceof Element ? event.target : event.target?.parentElement;
    if (!source) {
        return;
    }

    const toggle = source.closest('.password-toggle');
    if (!toggle) {
        return;
    }

    const targetId = toggle.getAttribute('data-target');
    const input = targetId
        ? document.getElementById(targetId)
        : toggle.closest('.relative')?.querySelector('input[type="password"], input[type="text"]');
    if (!input) {
        return;
    }

    const icon = toggle.querySelector('.material-symbols-outlined');
    const shouldShow = input.type === 'password';

    input.type = shouldShow ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
    toggle.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
    if (icon) {
        icon.textContent = shouldShow ? 'visibility_off' : 'visibility';
    }
});
</script>
</body>
</html>
