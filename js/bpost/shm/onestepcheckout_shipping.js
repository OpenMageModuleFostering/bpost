var shippingParameters = {};

Bpost.ShM.addMethods({
    initialize: function (settings, currentShippingMethod) {
        this.settings = settings;
        this.container = $$('.shipment-methods')[0];

        $$(".page")[0].insert({'before': $("bpost-info-wrapper")});

        this.googleMapsPopupContainer = $('bpost-info-wrapper');
        this.googleMapsPopup = new Control.Modal(this.googleMapsPopupContainer, {
            overlayOpacity: 0.65,
            fade: true,
            fadeDuration: 0.3,
            position: 'center_once'
        });


        if(currentShippingMethod.substring(0,14) == "bpostshm_bpost"){
            var hook = 'label[for="s_method_'+currentShippingMethod+'"]';
            this.container.down(hook).insert({'after': $("bpostDelivery")});
        }

        if(currentShippingMethod == carrier+"_bpost_parcellocker"){
            this.container.down('label[for="s_method_'+carrier+'_bpost_parcellocker"]').insert({'after': $("bpostShm")});
            activeOption = "s_method_"+carrier+"_bpost_parcellocker";
            $('selectPickupPoint').style.display = 'inline';
        }

        if(currentShippingMethod == carrier+"_bpost_pickuppoint"){
            this.container.down('label[for="s_method_'+carrier+'_bpost_pickuppoint"]').insert({'after': $("bpostShm")});
            activeOption = "s_method_"+carrier+"_bpost_pickuppoint";
            $('selectPickupPoint').style.display = 'inline';
        }

        //init datepicker if bpost carrier is selected
        if(currentShippingMethod == carrier+"_bpost_homedelivery" || currentShippingMethod == carrier+"_bpost_international")
        {
            if(this.settings.datepicker_display) {
                this.container.down('label[for="s_method_' + currentShippingMethod + '"]').insert({'after': $("bpostDelivery")});
                $("bpostDelivery").style.display = 'block';

                var currMethod = currentShippingMethod.replace(carrier+'_', '');
                this.placeDates(this.settings.datepicker_days, currMethod);
            }
        }

        this.selectPickupPointLinkClick = this.selectPickupPointLinkClick.bind(this);
        this.resolveSettings = this.resolveSettings.bind(this);
        this.openInline = this.openInline.bind(this);
        this.showDates = this.showDates.bind(this);
        this.deliveryDate = this.deliveryDate.bind(this);
        this.drawMap = this.drawMap.bind(this);
        this.showExtraInfo = this.showExtraInfo.bind(this);
        this.insertAutocomplete = this.insertAutocomplete.bind(this);
        this.pinMarkers = this.pinMarkers.bind(this);
        this.clearMarkers = this.clearMarkers.bind(this);
        this.filterMarkers = this.filterMarkers.bind(this);
        this.reloadMarkers = this.reloadMarkers.bind(this);
        this.selectSpot = this.selectSpot.bind(this);
        this.clickSpot = this.clickSpot.bind(this);
        this.closeInfobox = this.closeInfobox.bind(this);
        this.bpostClose = this.bpostClose.bind(this);

        this.imageOpenPostOffice = {
            url: this.settings.base_url + 'skin/frontend/base/default/images/bpost/location_postoffice_default.png',
            size: new google.maps.Size(24, 24),
            origin: new google.maps.Point(0, 0),
            anchor: new google.maps.Point(24, 36)
        };
        this.imageOpenPostPoint = {
            url: this.settings.base_url + 'skin/frontend/base/default/images/bpost/location_postpoint_default.png',
            size: new google.maps.Size(24, 24),
            origin: new google.maps.Point(0, 0),
            anchor: new google.maps.Point(24, 36)
        };
        this.imageOpenParcelLocker = {
            url: this.settings.base_url + 'skin/frontend/base/default/images/bpost/location_parcellocker_default.png',
            size: new google.maps.Size(24, 24),
            origin: new google.maps.Point(0, 0),
            anchor: new google.maps.Point(24, 36)
        };
        this.mapOptions = {
            zoom: 11,
            panControl: false,
            zoomControl: true,
            zoomControlOptions: {
                style: google.maps.ZoomControlStyle.SMALL,
                position: google.maps.ControlPosition.TOP_RIGHT
            },
            mapTypeControl: false,
            scaleControl: false,
            streetViewControl: false,
            overviewMapControl: false,
            center: new google.maps.LatLng(51, 4),
            styles: [
                {"featureType": "all", "elementType": "all", "stylers": [
                    {"saturation": -93},
                    {"lightness": 8}
                ]},
                {featureType: "poi", stylers: [
                    {visibility: "off"}
                ]}
            ]
        };
        this.infoboxOptions = {
            content: document.createElement("div"),
            disableAutoPan: false,
            maxWidth: 0,
            pixelOffset: new google.maps.Size(0, -10),
            zIndex: null,
            boxStyle: {
                width: "235px"
            },
            closeBoxURL: "",
            infoBoxClearance: new google.maps.Size(20, 20),
            isHidden: false,
            pane: "floatPane",
            enableEventPropagation: true
        };
        this.shape = {
            coord: [1, 1, 1, 45, 45, 45, 45, 1],
            type: 'poly'
        };

        if (typeof $(document).eventsBinded == "undefined" && initialized == false) {
            this.bindEvents();
        }

        initialized = true;
    },
    selectPickupPointLinkClick: function () {
        //set shipping parameters
        this.setShippingParameters();

        //first check if all necessary parameters are set
        if(shippingParameters["address_id"] == null && shippingParameters["postcode"] == "" && shippingParameters["city"] == ""){
            alert(this.settings.onestepcheckout_shipping_address_error);
            return false;
        }

        //start resolving setings
        this.resolveSettings();

        var mapcontainer = new Element('div', { 'id': 'mapcontainer', 'class': 'mapcontainer'})
            .insert(this.html_filter)
            .insert(this.html_list)
            .insert(this.html_map)
            .insert(this.html_clear);

        if(this.settings.onestepcheckout_active == true){
            $("bpost-info-wrapper").addClassName("active");
        }

        this.googleMapsPopup.open();

        $("bpostinfo").update(mapcontainer).down(".filter", 0);

        if (this.iecompat) {
            $('bpost-gmaps-filter').value = this.settings.label_postcode;
        }

        //place loader and show it
        $('map-canvas').update(this.html_loading);

        //call AJAX functionality
        this.triggerWindowAjaxRequest(shippingParameters);

        this.insertAutocomplete();
    },
    insertAutocomplete: function () {
        var inputEl = $('bpost-gmaps-filter');

        new google.maps.places.Autocomplete(inputEl);
    },
    drawMap: function () {
        $('bpostlist').setStyle({
            width: '220px'
        })

        $('map-canvas').setStyle({
            width: '515px'
        })

        this.map = null;
        this.map = new google.maps.Map($('map-canvas'), this.mapOptions);
    },
    setShippingParameters: function () {

        //add extra parameters
        //we get the selected shipping address data
        //first check if use billing for shipping is enabled
        shippingParameters = {};
        shippingParameters["pointType"] = $("selectPickupPoint").getAttribute("type");

        var indexMapping = {
            "billing:city": "city",
            "shipping:city": "city",
            "billing:postcode": "postcode",
            "shipping:postcode": "postcode",
            "billing:street1": "street",
            "shipping:street1": "street"
        };

        if($('billing:use_for_shipping_yes').checked){
            var savedBillingItems = $('billing-address-select');
            if(savedBillingItems && savedBillingItems.getValue()){
                shippingParameters["address_id"] = savedBillingItems.getValue();
            } else {
                shippingParameters["address_id"] = null;
                var items = $$('input[name^=billing]').concat($$('select[name^=billing]'));
                items.each(function(s) {
                    if(s.getStyle('display') != 'none' && s.id == "billing:city" || s.id == "billing:postcode" || s.id == "billing:street1"){
                        shippingParameters[indexMapping[s.id]] = s.getValue();
                    }
                });
            }
        }else{
            var savedShippingItems = $('shipping-address-select');
            if(savedShippingItems && savedShippingItems.getValue()){
                shippingParameters["address_id"] = savedShippingItems.getValue();
            } else {
                shippingParameters["address_id"] = null;
                var items = $$('input[name^=shipping]').concat($$('select[name^=shipping]'));
                items.each(function(s) {
                    if(s.getStyle('display') != 'none' && s.id == "shipping:city" || s.id == "shipping:postcode" || s.id == "shipping:street1"){
                        shippingParameters[indexMapping[s.id]] = s.getValue();
                    }
                });
            }
        }
    }
})