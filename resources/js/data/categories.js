export const categoryOptions = [
    { label: 'Groceries', group: 'Needs' },
    { label: 'Dining', group: 'Wants' },
    { label: 'Transportation', group: 'Needs' },
    { label: 'Housing', group: 'Needs' },
    { label: 'Shopping', group: 'Wants' },
    { label: 'Subscriptions', group: 'Needs' },
    { label: 'Misc', group: 'Wants' },
];

export const categoryGroups = categoryOptions.reduce((acc, option) => {
    acc[option.label] = option.group;
    return acc;
}, {});

export const categoryLabels = categoryOptions.map((option) => option.label);
