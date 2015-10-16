'use strict'

getLocation();

function getLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(getMeAllOfThePizza);
    } else {
        alert("Geolocation is not supported by this browser.");
    }
}

function getMeAllOfThePizza(position) {
    $.post(
        "getNearbyPizza.php",
        { curlat: position.coords.latitude, curlon: position.coords.longitude },
        $.proxy(this.showPizza, this)
    );
}

function showPizza(result) {
    var data = $.parseJSON(result);
    if(data.error === true) {
        console.log("Error: " + data.errormessage);
        return;
    }
    if(data.pizzalist.length < 1) {
        console.log("Error: No yummy pizza found around you!");
        return;
    }
    $( "div.jumbotron div.container").remove();
    $.each(data.pizzalist, function(index, value) {
        addPizzaElement(value);
    });
}

function addPizzaElement(item) {
    $( "div.jumbotron").append(
        '<div class="container">' +
        '<h1>' + item.name + '</h1>' +
        '<p>Rating: ' + item.grade + '</p>' +
        '<p><a class="btn btn-primary btn-lg" target="new" href="' + item.mapsLink + '" role="button">Show on map &raquo;</a></p>' +
        '</div>');
}