import Ajax from 'core/ajax';

const resolveElement = (selector) => {
    if (!selector) {
        return null;
    }
    if (typeof selector !== 'string') {
        return selector.length ? selector[0] : selector;
    }
    return document.getElementById(selector) || document.querySelector(selector);
};

/**
 * Called by core's autocomplete element (data-ajax attribute).
 */
export const transport = (selector, query, success, failure) => {
    const el = resolveElement(selector);
    const categoryid = el ? el.getAttribute('data-categoryid') : 0;

    if (!categoryid) {
        failure('Vikesa course wizard: could not resolve the category for this picker.');
        return;
    }

    Ajax.call([{
        methodname: 'local_vikesa_coursewizard_search_users',
        args: {categoryid: parseInt(categoryid, 10), query: query},
    }])[0].then(success).catch(failure);
};

export const processResults = (selector, results) => {
    return results.map((r) => ({value: r.id, label: r.fullname}));
};
