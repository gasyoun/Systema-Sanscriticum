{{--
    awcodes/filament-tiptap-editor: $watch('state') → updateEditorContent()
    всегда делает editor.focus(). Синхронизация entangle срабатывает и при
    клике в другое поле формы — фокус возвращался в редактор, страница
    прыгала к нему, клик терялся, текст печатался в редактор (форма поста
    календаря контента, 26-10-2026). Пока пользователь не в редакторе,
    содержимое обновляем молча, без захвата фокуса.
--}}
<script>
    (() => {
        const patch = () => {
            if (! window.Alpine) {
                return;
            }

            document.querySelectorAll('.tiptap-wrapper').forEach((el) => {
                if (el.__tiptapFocusFix) {
                    return;
                }

                let data;
                try {
                    data = window.Alpine.$data(el);
                } catch (e) {
                    return;
                }

                if (! data || typeof data.updateEditorContent !== 'function' || typeof data.editor !== 'function') {
                    return;
                }

                const original = data.updateEditorContent;
                data.updateEditorContent = function (content) {
                    const editor = this.editor();
                    if (editor && ! editor.isFocused && ! (content instanceof Event)) {
                        if (editor.isEditable) {
                            editor.commands.setContent(content, false);
                        }

                        return;
                    }

                    return original.call(this, content);
                };
                el.__tiptapFocusFix = true;
            });
        };

        let queued = false;
        new MutationObserver(() => {
            if (queued) {
                return;
            }
            queued = true;
            requestAnimationFrame(() => {
                queued = false;
                patch();
            });
        }).observe(document.documentElement, { childList: true, subtree: true });

        document.addEventListener('alpine:initialized', patch);
        document.addEventListener('livewire:navigated', patch);
    })();
</script>
