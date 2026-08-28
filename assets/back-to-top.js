const navbarFixLink = document.createElement('link');
navbarFixLink.rel = 'stylesheet';
navbarFixLink.href = 'assets/navbar-fix.css';
document.head.appendChild(navbarFixLink);

document.addEventListener('DOMContentLoaded', function () {
    const navbarBrand = document.querySelector('.navbar-brand');

    if (navbarBrand) {
        navbarBrand.textContent = 'Dokter Reminder RSU Islam Klaten';
    }

    const navbarMenu = document.querySelector('#mainNavbar .navbar-nav');

    if (navbarMenu) {
        let leaveLink = navbarMenu.querySelector('a[href="dokter_ijin.php"]');

        if (!leaveLink) {
            const reportLink = navbarMenu.querySelector('a[href="report.php"]');
            leaveLink = document.createElement('a');

            leaveLink.className = 'nav-link';
            leaveLink.href = 'dokter_ijin.php';
            leaveLink.textContent = 'Dokter Ijin';

            if (reportLink && reportLink.nextSibling) {
                navbarMenu.insertBefore(leaveLink, reportLink.nextSibling);
            } else {
                navbarMenu.appendChild(leaveLink);
            }
        }

        if (!navbarMenu.querySelector('a[href="pindah_jam_praktek.php"]')) {
            const movedPracticeLink = document.createElement('a');

            movedPracticeLink.className = 'nav-link';
            movedPracticeLink.href = 'pindah_jam_praktek.php';
            movedPracticeLink.textContent = 'Pindah Jam Praktek';

            if (leaveLink && leaveLink.nextSibling) {
                navbarMenu.insertBefore(movedPracticeLink, leaveLink.nextSibling);
            } else {
                navbarMenu.appendChild(movedPracticeLink);
            }
        }
    }

    const button = document.createElement('button');

    button.type = 'button';
    button.id = 'backToTop';
    button.setAttribute('aria-label', 'Kembali ke atas');
    button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>';
    button.style.position = 'fixed';
    button.style.right = '20px';
    button.style.bottom = '20px';
    button.style.width = '44px';
    button.style.height = '44px';
    button.style.display = 'none';
    button.style.alignItems = 'center';
    button.style.justifyContent = 'center';
    button.style.border = '0';
    button.style.borderRadius = '50%';
    button.style.background = '#009747';
    button.style.color = '#ffffff';
    button.style.boxShadow = 'none';
    button.style.cursor = 'pointer';
    button.style.zIndex = '1040';
    button.style.padding = '0';

    document.body.appendChild(button);

    function updateVisibility() {
        button.style.display = window.scrollY > 300 ? 'flex' : 'none';
    }

    window.addEventListener('scroll', updateVisibility, { passive: true });

    button.addEventListener('click', function () {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });

    const dashboardTitle = Array.from(document.querySelectorAll('h1')).find(function (element) {
        return element.textContent.trim() === 'Reminder Jadwal Dokter';
    });

    if (dashboardTitle) {
        const dashboardHeader = dashboardTitle.closest('.row');
        const filterForm = dashboardHeader
            ? dashboardHeader.querySelector('form[method="get"]')
            : null;
        const practiceDateElement = dashboardHeader
            ? dashboardHeader.querySelector('p.text-secondary')
            : null;
        const practiceDate = practiceDateElement
            ? practiceDateElement.textContent.trim()
            : '';

        if (filterForm) {
            const submitButton = filterForm.querySelector('button[type="submit"]');
            const doctorFilter = filterForm.querySelector('#doctorFilter');
            const poliFilter = filterForm.querySelector('#poliFilter');
            const scheduleDate = filterForm.querySelector('#scheduleDate');
            let submitting = false;

            if (submitButton) {
                submitButton.remove();
            }

            function submitFilter() {
                if (submitting) {
                    return;
                }

                submitting = true;
                window.setTimeout(function () {
                    filterForm.submit();
                }, 50);
            }

            if (doctorFilter) {
                doctorFilter.addEventListener('change', submitFilter);
            }

            if (poliFilter) {
                poliFilter.addEventListener('change', submitFilter);
            }

            if (scheduleDate) {
                scheduleDate.addEventListener('change', submitFilter);
            }

            if (window.jQuery) {
                const $ = window.jQuery;

                if (doctorFilter) {
                    $(doctorFilter)
                        .off('.autoFilter')
                        .on('select2:select.autoFilter select2:clear.autoFilter change.autoFilter', submitFilter);
                }

                if (poliFilter) {
                    $(poliFilter)
                        .off('.autoFilter')
                        .on('select2:select.autoFilter select2:clear.autoFilter change.autoFilter', submitFilter);
                }
            }
        }

        if (practiceDate !== '') {
            document.querySelectorAll('h3.h5.mb-1').forEach(function (doctorName) {
                const identity = doctorName.parentElement;
                const card = doctorName.closest('.card');

                if (!identity || !card || identity.querySelector('.doctor-practice-date')) {
                    return;
                }

                const practiceInfo = document.createElement('div');
                practiceInfo.className = 'doctor-practice-date small mt-1';
                practiceInfo.innerHTML = '<span class="text-secondary">Praktek:</span> <span class="fw-semibold">' + practiceDate + '</span>';

                const timeValues = Array.from(
                    card.querySelectorAll('.list-group-item .fw-semibold.text-nowrap')
                )
                    .map(function (element) {
                        return element.textContent.trim().replace(/\s+/g, ' ');
                    })
                    .filter(function (value, index, values) {
                        return value !== '' && values.indexOf(value) === index;
                    });

                if (timeValues.length > 0) {
                    practiceInfo.innerHTML += '<br><span class="text-secondary">Jam:</span> <span class="fw-semibold">' + timeValues.join(', ') + '</span>';
                }

                identity.appendChild(practiceInfo);
            });
        }
    }

    if (!document.querySelector('footer')) {
        const footer = document.createElement('footer');

        footer.className = 'border-top bg-white py-4 text-center text-secondary small mt-4';
        footer.textContent = 'DokterReminder · PHP Native + MySQL + whatsapp-web.js';

        document.body.appendChild(footer);
    }

    updateVisibility();
});
