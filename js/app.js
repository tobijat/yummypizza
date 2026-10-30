'use strict';

const radius = document.querySelector('#radius');
const status = document.querySelector('#status');
const results = document.querySelector('#results');
const alexanderplatz = { lat: 52.521918, lon: 13.413215 };

let here = null;
let requestId = 0;
let locationRequest = 0;

document.querySelector('#locate').addEventListener('click', locate);
document.querySelector('#alexanderplatz').addEventListener('click', () => {
    locationRequest++;
    load(alexanderplatz);
});
radius.addEventListener('change', () => {
    if (here) {
        load(here);
    }
});
document.querySelector('#controls').addEventListener('submit', (event) => {
    event.preventDefault();
    if (here) {
        load(here);
    }
});

locate();

function locate() {
    if (!navigator.geolocation) {
        status.textContent = 'Awww.. Your browser does not tell me your location. I guess you have to find some yummy pizza without my help.';
        return;
    }

    const id = ++locationRequest;
    status.textContent = 'Retrieving all of the Pizza for you!';
    navigator.geolocation.getCurrentPosition(
        (position) => {
            if (id !== locationRequest) {
                return;
            }
            load({
                lat: position.coords.latitude,
                lon: position.coords.longitude,
            });
        },
        () => {
            if (id !== locationRequest) {
                return;
            }
            status.textContent = 'Awww.. Your browser does not tell me your location. I guess you have to find some yummy pizza without my help.';
        },
        { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 },
    );
}

async function load(coords) {
    const id = ++requestId;
    here = coords;
    status.textContent = 'Retrieving all of the Pizza for you!';
    results.replaceChildren();
    results.setAttribute('aria-busy', 'true');

    try {
        const params = new URLSearchParams({
            lat: String(coords.lat),
            lon: String(coords.lon),
            radius: radius.value,
        });
        const response = await fetch('getNearbyPizza.php?' + params.toString());
        const data = await response.json();
        if (id !== requestId) {
            return;
        }
        if (data.error) {
            status.textContent = data.message || 'Could not load pizza places.';
            return;
        }
        status.textContent = describe(data);
        render(data.places || []);
    } catch (error) {
        if (id !== requestId) {
            return;
        }
        status.textContent = 'Could not load pizza places.';
    } finally {
        if (id === requestId) {
            results.removeAttribute('aria-busy');
        }
    }
}

function describe(data) {
    return data.message || '';
}

function render(places) {
    const items = places.map((place, index) => {
        const item = element('li', 'card');
        const body = element('div');
        const heading = element('h3');
        heading.append(pin(place), document.createTextNode(place.name || ''));

        const rating = element(
            'p',
            'meta',
            'Distance: ' + formatDistance(place.distanceKm) + ' | Rating: ' + formatScore(place.rating),
        );
        const ratings = element(
            'p',
            'meta',
            'Quality: ' + place.quality + ' | Price: ' + place.price,
        );

        body.append(heading);
        if (place.permanentlyClosed) {
            body.append(element('p', 'closed', 'Permanently closed'));
        }
        body.append(rating, ratings);

        const when = visitLabel(place);
        if (when) {
            body.append(element('p', 'when', when));
        }

        if (place.description) {
            const notes = element('p', 'notes', place.description);
            notes.lang = 'de';
            body.append(notes);
        }

        const href = mapsHref(place);
        if (href !== '') {
            const matched = /^ChIJ[\w-]{10,}$/.test(place.googlePlaceId || '');
            const tone = place.permanentlyClosed ? 'closed' : (matched ? 'open' : 'coordinates');
            const label = matched ? 'Show on map »' : 'Show coordinates on map';
            const link = element('a', 'map-link map-link-' + tone, label);
            link.href = href;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            body.append(link);
        }

        item.append(element('span', 'rank', String(index + 1)), body);
        return item;
    });
    results.replaceChildren(...items);
}

function mapsHref(place) {
    const name = String(place.name || '').trim();
    const lat = Number(place.latitude);
    const lon = Number(place.longitude);
    const placeId = typeof place.googlePlaceId === 'string' ? place.googlePlaceId : '';
    if (/^ChIJ[\w-]{10,}$/.test(placeId)) {
        const params = new URLSearchParams({
            api: '1',
            query: name || 'pizza',
            query_place_id: placeId,
        });
        return 'https://www.google.com/maps/search/?' + params.toString();
    }
    if (Number.isFinite(lat) && Number.isFinite(lon)) {
        return 'https://maps.google.com/maps?q=' + lat + ',' + lon + '&ll=' + lat + ',' + lon + '&z=17';
    }
    return '';
}

function pin(place) {
    const item = element('span', 'pin pin-' + (place.pinColor || 'red'));
    item.append(element('span', 'visually-hidden', place.pinLabel || ''));
    return item;
}

function visitLabel(place) {
    return place.visitedOn ? formatDate(place.visitedOn) : '';
}

function formatDistance(km) {
    const value = Number(km);
    return Number.isFinite(value) ? value.toFixed(2) + 'km' : '';
}

function formatScore(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '';
    }
    return String(Math.round(number * 10) / 10);
}

function formatDate(iso) {
    const parts = String(iso).split('-').map(Number);
    if (parts.length !== 3 || parts.some((part) => !Number.isFinite(part))) {
        return '';
    }
    const day = String(parts[2]).padStart(2, '0');
    const month = String(parts[1]).padStart(2, '0');
    return day + '.' + month + '.' + parts[0];
}

function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== undefined && text !== null) {
        node.textContent = text;
    }
    return node;
}
