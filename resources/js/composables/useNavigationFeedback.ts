import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

type RemoveListener = () => void;

export const useNavigationFeedback = (): {
    navigating: Ref<boolean>;
} => {
    const navigating = ref(false);

    let removeStart: RemoveListener | null = null;
    let removeFinish: RemoveListener | null = null;

    onMounted(() => {
        removeStart = router.on('start', () => {
            navigating.value = true;
        });

        removeFinish = router.on('finish', () => {
            navigating.value = false;
        });
    });

    onBeforeUnmount(() => {
        removeStart?.();
        removeFinish?.();
    });

    return {
        navigating,
    };
};
