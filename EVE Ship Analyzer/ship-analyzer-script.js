jQuery(document).ready(function($) {
    // --- DOM Elements ---
    const fetchButton = $('#fetchButton');
    const charNamesTextarea = $('#charNames');
    const statusDiv = $('#status');
    const resultsDiv = $('#results');

    fetchButton.on('click', function() {
        fetchButton.prop('disabled', true);
        statusDiv.html('Processing... This may take a while.');
        resultsDiv.html('');

        const charNames = charNamesTextarea.val();

        // AJAX call to our PHP backend
        $.post(esa_ajax_obj.ajax_url, {
            action: 'esa_fetch_data', // This maps to our wp_ajax_ hook in PHP
            nonce: esa_ajax_obj.nonce,
            char_names: charNames
        })
        .done(function(response) {
            if (response.success) {
                const data = response.data;
                displayResults(data.results, data.ship_names, data.errors);
                statusDiv.html('Analysis complete!');
            } else {
                statusDiv.html('Error: ' + response.data.message);
            }
        })
        .fail(function() {
            statusDiv.html('An unexpected server error occurred. Please try again later.');
        })
        .always(function() {
            fetchButton.prop('disabled', false);
        });
    });

    function displayResults(allData, namesMap, errors) {
        let finalHtml = '';

        // Display successful results
        for (const charName in allData) {
            const shipCounts = allData[charName];
            // Convert to array and sort
            const sortedShips = Object.entries(shipCounts).sort(([, a], [, b]) => b - a);
            
            finalHtml += `<div class="character-result"><h2>${charName}</h2>`;
            if (sortedShips.length > 0) {
                 finalHtml += `<ul class="ship-list">`;
                for (const [shipId, count] of sortedShips) {
                    const shipName = namesMap[shipId] || `Unknown Ship (ID: ${shipId})`;
                    finalHtml += `<li><span class="ship-name">${shipName}</span> <span class="ship-count">${count} kills</span></li>`;
                }
                finalHtml += `</ul>`;
            } else {
                finalHtml += `<p>No matching ship usage data found after ESI verification.</p>`;
            }
            finalHtml += `</div>`;
        }

        // Display errors for characters that failed
        for (const charName in errors) {
            finalHtml += `<div class="character-result"><h2>${charName}</h2><p>${errors[charName]}</p></div>`;
        }
        
        resultsDiv.html(finalHtml);
    }
});