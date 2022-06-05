(() => {
    window.addEventListener('load', () => {
        document.querySelectorAll('.tweet-list li').forEach((li) => {
            if (li.nextElementSibling && li.nextElementSibling.nodeName.toUpperCase() === 'UL') {
                li.querySelector('span.expando').addEventListener('click', (e) => {
                    e.target.parentElement.classList.toggle('collapsed');
                });
            } else {
                const expando = li.querySelector('span.expando');
                expando.parentNode.removeChild(expando);
            }
        });
    });
})();
