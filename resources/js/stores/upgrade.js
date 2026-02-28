import { reactive } from 'vue';

const planOrder = {
    starter: 0,
    pro: 1,
    premium: 2,
};

export const upgradePrompt = reactive({
    open: false,
    plan: 'pro',
    feature: 'this feature',
});

export function isPlanSufficient(currentPlan, requiredPlan) {
    const current = planOrder[currentPlan] ?? 0;
    const required = planOrder[requiredPlan] ?? 0;
    return current >= required;
}

export function showUpgrade(plan, feature) {
    upgradePrompt.open = true;
    upgradePrompt.plan = plan;
    upgradePrompt.feature = feature || 'this feature';
}

export function hideUpgrade() {
    upgradePrompt.open = false;
}
