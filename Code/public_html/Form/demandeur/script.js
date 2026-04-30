// Si la carte recoit une position demandeur dans l URL,
// on l utilisera pour ouvrir la carte autour de cette adresse.
const params = new URLSearchParams(window.location.search);
const userLat = parseFloat(params.get("user_lat"));
const userLng = parseFloat(params.get("user_lng"));
const hasUserPosition = Number.isFinite(userLat) && Number.isFinite(userLng);

const map = L.map("map").setView(
    hasUserPosition ? [userLat, userLng] : [47.5101, 6.7985],
    hasUserPosition ? 13 : 11
);

L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "&copy; OpenStreetMap contributors"
}).addTo(map);

const groupLayers = new Map();
const panel = document.getElementById("panel");
const panelTitle = document.getElementById("panel-title");
const panelList = document.getElementById("panel-list");
const closeButton = document.getElementById("close-panel");

const toggleFiltersButton = document.getElementById("toggle-filters");
const filtersBox = document.getElementById("filters-box");
const filterPriceInput = document.getElementById("filter-price");
const filterTimeStartInput = document.getElementById("filter-time-start");
const filterTimeEndInput = document.getElementById("filter-time-end");
const applyFiltersButton = document.getElementById("apply-filters");
const resetFiltersButton = document.getElementById("reset-filters");

let userMarker = null;
let destinationMarker = null;
let allGroups = [];

closeButton.addEventListener("click", () => {
    panel.classList.remove("open");
    clearDestinationMarker();
});

function setFiltersOpen(isOpen) {
    document.body.classList.toggle("filters-open", isOpen);
    filtersBox.toggleAttribute("hidden", !isOpen);
    toggleFiltersButton.textContent = isOpen ? "Fermer les filtres" : "Ouvrir les filtres";
}

toggleFiltersButton.addEventListener("click", () => {
    setFiltersOpen(filtersBox.hasAttribute("hidden"));
});

setFiltersOpen(false);

applyFiltersButton.addEventListener("click", () => {
    const filteredGroups = filterGroups(allGroups);
    renderGroups(filteredGroups);
});

resetFiltersButton.addEventListener("click", () => {
    filterPriceInput.value = "";
    filterTimeStartInput.value = "";
    filterTimeEndInput.value = "";
    renderGroups(allGroups);
});

loadGroups().catch((error) => {
    console.error(error.message);
});

async function loadGroups() {
    const response = await fetch("groupes.php");

    if (!response.ok) {
        throw new Error("Impossible de charger les groupes offreurs.");
    }

    const payload = await response.json();

    if (!payload.success || !Array.isArray(payload.groups)) {
        throw new Error(payload.message || "Reponse serveur invalide.");
    }

    allGroups = payload.groups;
    renderGroups(allGroups);
}

function renderGroups(groups) {
    groupLayers.forEach((layer) => map.removeLayer(layer));
    groupLayers.clear();
    clearDestinationMarker();

    if (groups.length === 0) {
        panel.classList.remove("open");

        if (hasUserPosition) {
            showUserMarker();
        }

        alert("Aucun trajet ne correspond aux filtres.");
        return;
    }

    const bounds = [];

    groups.forEach((group) => {
        const radius = calculateVisualRadius(group.address_count);

        const circle = L.circle([group.center_lat, group.center_lng], {
            color: "#b91c1c",
            fillColor: "#ef4444",
            fillOpacity: 0.35,
            radius
        });

        circle.on("click", () => {
            renderPanel(group);
        });

        circle.addTo(map);
        groupLayers.set(group.id, circle);
        bounds.push([group.center_lat, group.center_lng]);
    });

    if (hasUserPosition) {
        showUserMarker();
        focusAroundUser(groups);
        return;
    }

    map.fitBounds(bounds, { padding: [40, 40] });
}

function filterGroups(groups) {
    const maxPrice = filterPriceInput.value;
    const timeStart = filterTimeStartInput.value;
    const timeEnd = filterTimeEndInput.value;

    return groups
        .map((group) => {
            const filteredOffers = group.offers.filter((offre) => {
                const prixOffre = getPrice(offre.prix);
                const okPrice = !maxPrice || prixOffre <= Number(maxPrice);
                const okTime = matchesTimeRange(offre.heure_depart, timeStart, timeEnd);

                return okPrice && okTime;
            });

            return {
                ...group,
                offers: filteredOffers,
                address_count: filteredOffers.length
            };
        })
        .filter((group) => group.offers.length > 0);
}

function getPrice(prix) {
    if (!prix || prix === "gratuit") {
        return 0;
    }

    const text = String(prix).replace(",", ".");
    const match = text.match(/[0-9]+(\.[0-9]+)?/);

    if (!match) {
        return 0;
    }

    return Number(match[0]);
}

function matchesTimeRange(offerTime, timeStart, timeEnd) {
    if (!timeStart && !timeEnd) {
        return true;
    }

    const offerMinutes = timeToMinutes(offerTime);

    if (offerMinutes === null) {
        return false;
    }

    const startMinutes = timeStart ? timeToMinutes(timeStart) : null;
    const endMinutes = timeEnd ? timeToMinutes(timeEnd) : null;

    if (startMinutes !== null && offerMinutes < startMinutes) {
        return false;
    }

    if (endMinutes !== null && offerMinutes > endMinutes) {
        return false;
    }

    return true;
}

function timeToMinutes(value) {
    if (!value) {
        return null;
    }

    const match = String(value).match(/^(\d{2}):(\d{2})/);

    if (!match) {
        return null;
    }

    const hours = Number(match[1]);
    const minutes = Number(match[2]);

    if (!Number.isFinite(hours) || !Number.isFinite(minutes)) {
        return null;
    }

    return (hours * 60) + minutes;
}

function calculateVisualRadius(addressCount) {
    const baseRadius = 90;
    const extraPerAddress = 40;
    return baseRadius + Math.max(0, addressCount - 1) * extraPerAddress;
}

function renderPanel(group) {
    panelTitle.textContent = `${group.address_count} offre${group.address_count > 1 ? "s" : ""} disponible${group.address_count > 1 ? "s" : ""}`;

    panelList.innerHTML = group.offers
        .map((offre) => {
            const dateTrajet = formatDate(offre.date_trajet);
            const heureDepart = formatText(offre.heure_depart);
            const heureArrivee = formatText(offre.heure_arrivee);
            const telephone = formatText(offre.telephone);
            const placesTexte = formatPlaces(offre.nb_participants, offre.places_proposees);
            const destinationTexte = formatText(offre.destination_label);

            return `
                <article class="card" data-offer-id="${offre.id_offre_demande}">
                    <div class="card-topline"></div>
                    <div class="card-header">
                        <div>
                            <p class="card-eyebrow">Conducteur</p>
                            <h3 class="card-name">${escapeHtml(offre.prenom)} ${escapeHtml(offre.nom)}</h3>
                        </div>
                        <span class="card-badge">${escapeHtml(offre.ville)}</span>
                    </div>

                    <div class="card-meta">
                        <p class="card-line"><strong>Téléphone</strong><span>${telephone}</span></p>
                        <p class="card-line"><strong>Adresse</strong><span>${escapeHtml(offre.adresse)}</span></p>
                        <p class="card-line"><strong>Date</strong><span>${dateTrajet}</span></p>
                        <p class="card-line"><strong>Départ</strong><span>${heureDepart}</span></p>
                        <p class="card-line"><strong>Arrivée</strong><span>${heureArrivee}</span></p>
                        <p class="card-line"><strong>Destination</strong><span>${destinationTexte}</span></p>
                        <p class="card-line"><strong>Prix</strong><span>${formatText(offre.prix)}</span></p>
                        <p class="card-line"><strong>Places</strong><span>${placesTexte}</span></p>
                    </div>

                    <div class="card-actions">
                        <button type="button" class="card-action" data-offer-id="${offre.id_offre_demande}">
                            Choisir ce conducteur
                        </button>
                        <button type="button" class="card-destination" data-offer-id="${offre.id_offre_demande}">
                            Voir la destination
                        </button>
                    </div>
                    <p class="card-feedback" id="feedback-${offre.id_offre_demande}"></p>
                </article>
            `;
        })
        .join("");

    panelList.querySelectorAll(".card-action").forEach((button) => {
        button.addEventListener("click", async () => {
            const offerId = Number(button.dataset.offerId);
            await createDemande(offerId, button);
        });
    });

    panelList.querySelectorAll(".card-destination").forEach((button) => {
        button.addEventListener("click", () => {
            const offerId = Number(button.dataset.offerId);
            const selectedOffer = group.offers.find((offre) => offre.id_offre_demande === offerId);

            if (!selectedOffer) {
                return;
            }

            showDestinationMarker(selectedOffer);
        });
    });

    panel.classList.add("open");
}

async function createDemande(selectedOfferId, button) {
    const feedback = document.getElementById(`feedback-${selectedOfferId}`);

    button.disabled = true;
    button.textContent = "Envoi...";

    if (feedback) {
        feedback.textContent = "";
    }

    try {
        const response = await fetch("create_demande.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                selected_offer_id: selectedOfferId
            })
        });

        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || "Impossible d enregistrer la demande.");
        }

        button.textContent = "Demande envoyee";

        if (feedback) {
            feedback.textContent = payload.message;
        }

        alert("Votre demande a bien ete pris en compte");
        window.location.href = "../../index.php";
    } catch (error) {
        button.disabled = false;
        button.textContent = "Choisir ce conducteur";

        if (feedback) {
            feedback.textContent = error.message;
        }
    }
}

function showUserMarker() {
    if (userMarker) {
        return;
    }

    userMarker = L.circleMarker([userLat, userLng], {
        radius: 8,
        color: "#1d4ed8",
        fillColor: "#60a5fa",
        fillOpacity: 0.9,
        weight: 2
    }).addTo(map);

    userMarker.bindTooltip("Votre adresse", {
        direction: "top",
        offset: [0, -8]
    });
}

function clearDestinationMarker() {
    if (!destinationMarker) {
        return;
    }

    map.removeLayer(destinationMarker);
    destinationMarker = null;
}

function showDestinationMarker(offre) {
    const latitude = Number(offre.destination_latitude);
    const longitude = Number(offre.destination_longitude);

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        alert("Destination non disponible pour ce conducteur.");
        return;
    }

    clearDestinationMarker();

    destinationMarker = L.circleMarker([latitude, longitude], {
        radius: 10,
        color: "#15803d",
        fillColor: "#22c55e",
        fillOpacity: 0.95,
        weight: 2
    }).addTo(map);

    destinationMarker.bindTooltip(`Destination : ${offre.destination_label || "Non renseignee"}`, {
        direction: "top",
        offset: [0, -10]
    }).openTooltip();

    map.flyTo([latitude, longitude], Math.max(map.getZoom(), 13));
}

function focusAroundUser(groups) {
    const nearestGroup = findNearestGroup(groups);

    if (!nearestGroup) {
        map.setView([userLat, userLng], 13);
        return;
    }

    const bounds = L.latLngBounds(
        [userLat, userLng],
        [nearestGroup.center_lat, nearestGroup.center_lng]
    );

    map.fitBounds(bounds, {
        padding: [80, 80],
        maxZoom: 13
    });
}

function findNearestGroup(groups) {
    let nearestGroup = null;
    let nearestDistance = Infinity;

    groups.forEach((group) => {
        const distance = map.distance([userLat, userLng], [group.center_lat, group.center_lng]);

        if (distance < nearestDistance) {
            nearestDistance = distance;
            nearestGroup = group;
        }
    });

    return nearestGroup;
}

function escapeHtml(value) {
    const safeValue = String(value ?? "");

    return safeValue
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#39;");
}

function formatText(value) {
    if (value === null || value === undefined || value === "") {
        return "Non renseigne";
    }

    return escapeHtml(value);
}

function formatDate(value) {
    if (!value) {
        return "Non renseignee";
    }

    const date = new Date(`${value}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return escapeHtml(value);
    }

    return escapeHtml(date.toLocaleDateString("fr-FR"));
}

function formatPlaces(nbParticipants, placesProposees) {
    const prises = Number.isFinite(Number(nbParticipants)) ? Number(nbParticipants) : 0;
    const total = Number.isFinite(Number(placesProposees)) ? Number(placesProposees) : 0;

    return `${prises}/${total}`;
}
