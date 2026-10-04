import { onBeforeUnmount, onMounted, type Ref } from 'vue';

export const useUnsavedChangesGuard = (
    dirty: Ref<boolean>,
    message = 'You have unsaved changes.',
): void => {
    const beforeUnload = (event: BeforeUnloadEvent): void => {
        if (!dirty.value) {
            return;
        }

        event.preventDefault();
        event.returnValue = message;
    };

    onMounted(() => {
        window.addEventListener('beforeunload', beforeUnload);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', beforeUnload);
    });
};
