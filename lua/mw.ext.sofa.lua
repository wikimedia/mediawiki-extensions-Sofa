local sofa = {}
local php

function sofa.setupInterface( options )
	-- Remove setup function
	sofa.setupInterface = nil

	-- Copy the PHP callbacks to a local variable, and remove the global
	php = mw_interface
	mw_interface = nil

	-- Do any other setup here

	-- Install into the mw global
	mw = mw or {}
	mw.ext = mw.ext or {}
	mw.ext.sofa = sofa

	-- Indicate that we're loaded
	package.loaded['mw.ext.sofa'] = sofa
end

function sofa.query( arg1 )
	results = php.query(arg1)
	for i in ipairs( results ) do
		results[i].title = mw.title.makeTitle( results[i].titleNS, results[i].titleText )
		results[i].titleNS = nil
		results[i].titleText = nil
		if results[i].value ~= nil then
			-- FIXME will we have nulls?
			results[i].value = mw.text.jsonDecode( results[i].value )
		end
	end
	return results
end


return sofa
